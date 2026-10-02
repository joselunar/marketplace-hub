<?php

namespace App\Services\Suppliers;

use App\Enums\SupplierStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\SupplierModel;
use App\Models\SyncLogModel;
use App\Services\Suppliers\Connectors\SupplierConnectorFactory;
use App\Services\Suppliers\Connectors\SupplierConnectorInterface;
use App\Services\Suppliers\Connectors\UnsupportedOperationException;
use InvalidArgumentException;
use Throwable;

/**
 * Sincronização periódica de estoque e custos com fornecedores que têm
 * adapter de integração registrado (Config\SupplierConnectors).
 *
 * Fornecedores manuais ou por CSV não entram aqui: sem uma fonte oficial
 * para consultar, a atualização continua sendo a importação do arquivo.
 */
class SupplierSyncService
{
    public const MODE_STOCK  = SupplierImportService::MODE_STOCK;
    public const MODE_PRICES = SupplierImportService::MODE_PRICES;

    private const ACTIONS = [
        self::MODE_STOCK  => 'sync_stock',
        self::MODE_PRICES => 'sync_prices',
    ];

    private const LABELS = [
        self::MODE_STOCK  => 'estoque',
        self::MODE_PRICES => 'custos',
    ];

    /**
     * Operação específica de cada modo; o catálogo completo serve de alternativa.
     */
    private const CAPABILITIES = [
        self::MODE_STOCK  => SupplierConnectorInterface::CAPABILITY_FETCH_STOCK,
        self::MODE_PRICES => SupplierConnectorInterface::CAPABILITY_FETCH_PRICES,
    ];

    public function __construct(
        private readonly SupplierModel $suppliers = new SupplierModel(),
        private readonly SyncLogModel $syncLogs = new SyncLogModel(),
        private readonly SupplierConnectorFactory $connectors = new SupplierConnectorFactory(),
        private readonly ?SupplierImportService $import = null,
        private readonly ?SupplierSelectionService $selection = null,
    ) {
    }

    /**
     * Fornecedores ativos com adapter e o que cada um permite sincronizar.
     *
     * @return list<array{id: int, name: string, integration_type: string, stock: bool, prices: bool}>
     */
    public function syncable(): array
    {
        $list = [];

        foreach ($this->suppliers->select('id, name, integration_type, status')->where('status', SupplierStatus::Active->value)->orderBy('name')->findAll() as $supplier) {
            if (! $this->connectors->hasAdapter((int) $supplier['id'])) {
                continue;
            }

            $stock  = $this->connectorFor($supplier, self::MODE_STOCK) !== null;
            $prices = $this->connectorFor($supplier, self::MODE_PRICES) !== null;

            if ($stock || $prices) {
                $list[] = [
                    'id'               => (int) $supplier['id'],
                    'name'             => (string) $supplier['name'],
                    'integration_type' => (string) $supplier['integration_type'],
                    'stock'            => $stock,
                    'prices'           => $prices,
                ];
            }
        }

        return $list;
    }

    /**
     * @return list<int>
     */
    public function syncableIds(string $mode): array
    {
        $this->assertMode($mode);

        return array_values(array_map(
            static fn (array $supplier): int => $supplier['id'],
            array_filter($this->syncable(), static fn (array $supplier): bool => $supplier[$mode]),
        ));
    }

    /**
     * Consulta o fornecedor, aplica o estoque ou os custos às ofertas existentes
     * e recalcula o fornecedor principal e o preço dos produtos afetados.
     *
     * @return array{updated: int, skipped: int, errors: int, changed: int}
     */
    public function sync(int $supplierId, string $mode, ?int $userId = null): array
    {
        $this->assertMode($mode);

        $supplier = $this->suppliers->find($supplierId) ?? throw new BusinessRuleException('Fornecedor não encontrado.');

        if ($supplier['status'] !== SupplierStatus::Active->value) {
            throw new BusinessRuleException(sprintf('O fornecedor "%s" não está ativo.', $supplier['name']));
        }

        $connector = $this->connectorFor($supplier, $mode) ?? throw new UnsupportedOperationException(sprintf(
            'O fornecedor "%s" não tem adapter registrado que consulte %s.',
            $supplier['name'],
            self::LABELS[$mode],
        ));

        $startedAt = date('Y-m-d H:i:s');

        try {
            $feed = match (true) {
                $connector->supports(self::CAPABILITIES[$mode]) => $mode === self::MODE_STOCK ? $connector->fetchStock() : $connector->fetchPrices(),
                default => $connector->fetchProducts(),
            };
            $stats = $this->import()->apply($supplierId, $feed, $mode);
        } catch (Throwable $e) {
            $this->log($supplierId, $mode, SyncLogModel::STATUS_ERROR, sprintf('Falha ao sincronizar %s: %s', self::LABELS[$mode], mb_substr($e->getMessage(), 0, 400)), $startedAt);
            // A falha pesa na confiabilidade do fornecedor.
            $this->selection()->selectForSupplier($supplierId, SupplierSelectionService::TRIGGER_SYNC, $userId);

            throw $e;
        }

        $errors = count($feed->errors);
        $this->log(
            $supplierId,
            $mode,
            $errors > 0 ? SyncLogModel::STATUS_WARNING : SyncLogModel::STATUS_SUCCESS,
            sprintf('Sincronização de %s: %d oferta(s) atualizada(s), %d SKU(s) desconhecido(s), %d erro(s) no retorno.', self::LABELS[$mode], $stats['updated'], $stats['skipped'], $errors),
            $startedAt,
            ['updated' => $stats['updated'], 'skipped' => $stats['skipped'], 'errors' => $errors, 'first_errors' => array_slice($feed->errors, 0, 10)],
        );

        $selection = $this->selection()->selectForSupplier($supplierId, SupplierSelectionService::TRIGGER_SYNC, $userId);

        return ['updated' => $stats['updated'], 'skipped' => $stats['skipped'], 'errors' => $errors, 'changed' => $selection['changed']];
    }

    private function connectorFor(array $supplier, string $mode): ?SupplierConnectorInterface
    {
        return $this->connectors->adapterFor($supplier, self::CAPABILITIES[$mode])
            ?? $this->connectors->adapterFor($supplier, SupplierConnectorInterface::CAPABILITY_FETCH_PRODUCTS);
    }

    private function log(int $supplierId, string $mode, string $status, string $message, string $startedAt, array $payload = []): void
    {
        $this->syncLogs->insert([
            'integration_type' => SupplierService::SYNC_INTEGRATION_TYPE,
            'integration_id'   => $supplierId,
            'action'           => self::ACTIONS[$mode],
            'status'           => $status,
            'message'          => $message,
            'payload'          => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            'started_at'       => $startedAt,
            'finished_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    private function assertMode(string $mode): void
    {
        if (! isset(self::ACTIONS[$mode])) {
            throw new InvalidArgumentException('Modo de sincronização inválido: ' . $mode);
        }
    }

    private function import(): SupplierImportService
    {
        return $this->import ?? new SupplierImportService(selection: $this->selection());
    }

    private function selection(): SupplierSelectionService
    {
        return $this->selection ?? service('supplierSelection');
    }
}

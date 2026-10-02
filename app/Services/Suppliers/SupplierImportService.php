<?php

namespace App\Services\Suppliers;

use App\DTO\Suppliers\SupplierFeedResult;
use App\DTO\Suppliers\SupplierProductData;
use App\Exceptions\BusinessRuleException;
use App\Models\ProductModel;
use App\Models\SupplierImportModel;
use App\Models\SupplierOfferModel;
use App\Models\SyncLogModel;
use App\Services\Audit\AuditService;
use App\Services\Suppliers\Connectors\SupplierConnectorFactory;
use InvalidArgumentException;
use Throwable;

/**
 * Aplica um feed de fornecedor (CSV hoje; API/XML no futuro) sobre as ofertas.
 *
 * - Ofertas são identificadas por (fornecedor, SKU do fornecedor).
 * - Ofertas novas são vinculadas ao catálogo por SKU interno (coluna product_sku)
 *   ou, na falta dele, por EAN. Sem correspondência, ficam sem vínculo.
 * - Ofertas ausentes no arquivo não são alteradas.
 */
class SupplierImportService
{
    private const MAX_STORED_ERRORS = 200;

    public function __construct(
        private readonly SupplierService $suppliers = new SupplierService(),
        private readonly SupplierOfferModel $offers = new SupplierOfferModel(),
        private readonly ProductModel $products = new ProductModel(),
        private readonly SupplierImportModel $imports = new SupplierImportModel(),
        private readonly SyncLogModel $syncLogs = new SyncLogModel(),
        private readonly SupplierConnectorFactory $connectors = new SupplierConnectorFactory(),
        private readonly ?AuditService $audit = null,
        private readonly ?SupplierSelectionService $selection = null,
    ) {
    }

    /**
     * Importa um CSV e devolve o ID do registro em supplier_imports.
     */
    public function importCsv(int $supplierId, string $filePath, string $originalName, ?int $userId = null): int
    {
        $supplier  = $this->suppliers->findOrFail($supplierId);
        $startedAt = date('Y-m-d H:i:s');

        $this->imports->insert([
            'supplier_id'       => $supplierId,
            'user_id'           => $userId,
            'source'            => 'csv',
            'original_filename' => mb_substr($originalName, 0, 255),
            'status'            => SupplierImportModel::STATUS_PROCESSING,
            'started_at'        => $startedAt,
        ]);
        $importId = (int) $this->imports->getInsertID();

        try {
            $feed  = $this->connectors->csv($filePath)->fetchProducts();
            $stats = $this->apply($supplierId, $feed);
        } catch (InvalidArgumentException $e) {
            // Arquivo estruturalmente inválido (sem colunas obrigatórias, vazio...).
            $this->finishFailed($importId, $supplierId, $startedAt, $e->getMessage());

            throw new BusinessRuleException($e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            log_message('error', 'Falha ao importar CSV do fornecedor {id}: {message}', ['id' => $supplierId, 'message' => $e->getMessage()]);
            $this->finishFailed($importId, $supplierId, $startedAt, 'Erro inesperado ao processar o arquivo. Consulte os logs do servidor.');

            throw $e;
        }

        $status = $feed->hasErrors()
            ? SupplierImportModel::STATUS_COMPLETED_WITH_ERRORS
            : SupplierImportModel::STATUS_COMPLETED;

        $this->imports->update($importId, [
            'status'        => $status,
            'total_rows'    => $feed->totalRows,
            'created_count' => $stats['created'],
            'updated_count' => $stats['updated'],
            'linked_count'  => $stats['linked'],
            'error_count'   => count($feed->errors),
            'errors'        => $feed->hasErrors() ? json_encode(array_slice($feed->errors, 0, self::MAX_STORED_ERRORS), JSON_UNESCAPED_UNICODE) : null,
            'finished_at'   => date('Y-m-d H:i:s'),
        ]);

        $summary = sprintf(
            'Importação CSV: %d linhas, %d ofertas criadas, %d atualizadas, %d vinculadas, %d erros.',
            $feed->totalRows,
            $stats['created'],
            $stats['updated'],
            $stats['linked'],
            count($feed->errors),
        );

        $this->syncLogs->insert([
            'integration_type' => SupplierService::SYNC_INTEGRATION_TYPE,
            'integration_id'   => $supplierId,
            'action'           => 'import_csv',
            'status'           => $feed->hasErrors() ? SyncLogModel::STATUS_WARNING : SyncLogModel::STATUS_SUCCESS,
            'message'          => $summary,
            'payload'          => json_encode(['import_id' => $importId] + $stats, JSON_UNESCAPED_UNICODE),
            'started_at'       => $startedAt,
            'finished_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->audit()->log('import', 'supplier', (int) $supplier['id'], null, ['import_id' => $importId, 'file' => $originalName] + $stats, $userId);

        // Custos, estoques e o histórico de sincronização do fornecedor mudaram.
        $this->selection()->selectForSupplier($supplierId, SupplierSelectionService::TRIGGER_IMPORT, $userId);

        return $importId;
    }

    /**
     * Grava os itens do feed em uma única transação.
     *
     * @return array{created: int, updated: int, linked: int}
     */
    public function apply(int $supplierId, SupplierFeedResult $feed): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'linked' => 0];
        $now   = date('Y-m-d H:i:s');
        $db    = $this->offers->db;

        $existingOffers = $this->existingOffers($supplierId);
        [$bySku, $byEan] = $this->candidateProducts($feed->items);

        $db->transException(true)->transStart();

        foreach ($feed->items as $item) {
            $existing = $existingOffers[mb_strtolower($item->supplierSku)] ?? null;
            $data     = $this->offerData($item, $now);

            $productId = $existing['product_id'] ?? null;

            if ($productId === null) {
                $productId = $bySku[$item->productSku ?? ''] ?? $byEan[$item->ean ?? ''] ?? null;

                if ($productId !== null) {
                    $data['product_id'] = $productId;
                    $stats['linked']++;
                }
            }

            if ($existing === null) {
                $this->offers->skipValidation(true)->insert($data + ['supplier_id' => $supplierId, 'active' => 1]);
                $stats['created']++;
            } else {
                $this->offers->skipValidation(true)->update($existing['id'], $data);
                $stats['updated']++;
            }
        }

        $db->transComplete();
        $this->offers->skipValidation(false);

        return $stats;
    }

    private function offerData(SupplierProductData $item, string $now): array
    {
        $data = [
            'supplier_sku'          => $item->supplierSku,
            'supplier_product_name' => $item->name,
            'cost_price'            => $item->costPrice,
            'stock_quantity'        => $item->stockQuantity,
            'last_sync_at'          => $now,
        ];

        if ($item->ean !== null) {
            $data['ean'] = $item->ean;
        }

        if ($item->shippingCost !== null) {
            $data['shipping_cost'] = $item->shippingCost;
        }

        if ($item->shippingDays !== null) {
            $data['shipping_days'] = $item->shippingDays;
        }

        return $data;
    }

    /**
     * @return array<string, array{id: int, product_id: int|null}> SKU do fornecedor (minúsculo) => oferta
     */
    private function existingOffers(int $supplierId): array
    {
        $map  = [];
        $rows = $this->offers->select('id, supplier_sku, product_id')->where('supplier_id', $supplierId)->findAll();

        foreach ($rows as $row) {
            $map[mb_strtolower($row['supplier_sku'])] = [
                'id'         => (int) $row['id'],
                'product_id' => $row['product_id'] !== null ? (int) $row['product_id'] : null,
            ];
        }

        return $map;
    }

    /**
     * Busca, em lotes, os produtos que podem casar por SKU interno ou EAN.
     *
     * @param list<SupplierProductData> $items
     *
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private function candidateProducts(array $items): array
    {
        $skus = array_values(array_unique(array_filter(array_map(static fn ($i) => $i->productSku, $items))));
        $eans = array_values(array_unique(array_filter(array_map(static fn ($i) => $i->ean, $items))));

        $bySku = [];
        $byEan = [];

        foreach (array_chunk($skus, 500) as $chunk) {
            foreach ($this->products->select('id, sku')->whereIn('sku', $chunk)->findAll() as $row) {
                $bySku[strtoupper($row['sku'])] = (int) $row['id'];
            }
        }

        foreach (array_chunk($eans, 500) as $chunk) {
            foreach ($this->products->select('id, ean')->whereIn('ean', $chunk)->findAll() as $row) {
                $byEan[$row['ean']] = (int) $row['id'];
            }
        }

        return [$bySku, $byEan];
    }

    private function finishFailed(int $importId, int $supplierId, string $startedAt, string $message): void
    {
        $now = date('Y-m-d H:i:s');

        $this->imports->update($importId, [
            'status'      => SupplierImportModel::STATUS_FAILED,
            'error_count' => 1,
            'errors'      => json_encode([['line' => 0, 'message' => $message]], JSON_UNESCAPED_UNICODE),
            'finished_at' => $now,
        ]);

        $this->syncLogs->insert([
            'integration_type' => SupplierService::SYNC_INTEGRATION_TYPE,
            'integration_id'   => $supplierId,
            'action'           => 'import_csv',
            'status'           => SyncLogModel::STATUS_ERROR,
            'message'          => $message,
            'payload'          => json_encode(['import_id' => $importId], JSON_UNESCAPED_UNICODE),
            'started_at'       => $startedAt,
            'finished_at'      => $now,
        ]);

        // A falha conta na confiabilidade do fornecedor.
        $this->selection()->selectForSupplier($supplierId, SupplierSelectionService::TRIGGER_IMPORT);
    }

    private function audit(): AuditService
    {
        return $this->audit ?? service('audit');
    }

    private function selection(): SupplierSelectionService
    {
        return $this->selection ?? service('supplierSelection');
    }
}

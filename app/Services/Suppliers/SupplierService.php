<?php

namespace App\Services\Suppliers;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\SupplierImportModel;
use App\Models\SupplierModel;
use App\Models\SupplierOfferModel;
use App\Models\SyncLogModel;
use App\Services\Audit\AuditService;
use App\Support\Input;

class SupplierService
{
    public const SYNC_INTEGRATION_TYPE = 'supplier';

    public function __construct(
        private readonly SupplierModel $suppliers = new SupplierModel(),
        private readonly SupplierOfferModel $offers = new SupplierOfferModel(),
        private readonly SyncLogModel $syncLogs = new SyncLogModel(),
        private readonly SupplierImportModel $imports = new SupplierImportModel(),
        private readonly ?AuditService $audit = null,
        private readonly ?SupplierSelectionService $selection = null,
    ) {
    }

    public function create(array $input): int
    {
        $data = $this->prepare($input);

        if (! $this->suppliers->insert($data)) {
            throw new ValidationException($this->suppliers->errors());
        }
        $id = (int) $this->suppliers->getInsertID();

        $this->audit()->log('create', 'supplier', $id, null, $data);

        return $id;
    }

    public function update(int $id, array $input): void
    {
        $current = $this->findOrFail($id);
        $data    = $this->prepare($input, $id);

        if (! $this->suppliers->update($id, $data)) {
            throw new ValidationException($this->suppliers->errors());
        }

        $this->audit()->log('update', 'supplier', $id, $current, $data);

        // Status, prioridade, prazo e estoque de segurança afetam o ranking.
        $this->selection()->selectForSupplier($id, SupplierSelectionService::TRIGGER_SUPPLIER);
    }

    public function delete(int $id): void
    {
        $current = $this->findOrFail($id);

        if ($this->offers->where('supplier_id', $id)->countAllResults() > 0) {
            throw new BusinessRuleException('Este fornecedor possui ofertas cadastradas. Inative o fornecedor em vez de excluí-lo.');
        }

        $this->suppliers->delete($id);
        $this->audit()->log('delete', 'supplier', $id, $current, null);
    }

    public function findOrFail(int $id): array
    {
        $supplier = $this->suppliers->find($id);

        if ($supplier === null) {
            throw new BusinessRuleException('Fornecedor não encontrado.');
        }

        return $supplier;
    }

    /**
     * Dados para a tela de detalhes do fornecedor.
     */
    public function details(int $id): array
    {
        $supplier = $this->findOrFail($id);

        $offerStats = $this->offers
            ->select('COUNT(*) AS total, SUM(active = 1) AS active_total, SUM(product_id IS NULL) AS unlinked, SUM(stock_quantity = 0) AS out_of_stock, MAX(last_sync_at) AS last_sync_at')
            ->where('supplier_id', $id)
            ->get()
            ->getRowArray();

        return [
            'supplier'      => $supplier,
            'offerStats'    => [
                'total'        => (int) ($offerStats['total'] ?? 0),
                'active'       => (int) ($offerStats['active_total'] ?? 0),
                'unlinked'     => (int) ($offerStats['unlinked'] ?? 0),
                'out_of_stock' => (int) ($offerStats['out_of_stock'] ?? 0),
            ],
            'lastSync'      => $this->syncLogs->latestFor(self::SYNC_INTEGRATION_TYPE, $id),
            'lastOfferSync' => $offerStats['last_sync_at'] ?? null,
            'recentErrors'  => $this->syncLogs->recentErrorsFor(self::SYNC_INTEGRATION_TYPE, $id),
            'recentImports' => $this->imports->where('supplier_id', $id)->orderBy('id', 'DESC')->findAll(5),
        ];
    }

    private function prepare(array $input, ?int $id = null): array
    {
        $data = Input::only($input, [
            'name', 'legal_name', 'document', 'email', 'phone', 'website',
            'integration_type', 'status', 'priority', 'default_shipping_days', 'safety_stock', 'notes',
        ]);

        $data['name']     = $data['name'] ?? '';
        $data['document'] = Input::digits($data['document'] ?? null);
        $data['email']    = isset($data['email']) ? mb_strtolower($data['email']) : null;
        $data['priority'] = $data['priority'] ?? 5;

        if ($id !== null) {
            $data['id'] = $id;
        }

        return $data;
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

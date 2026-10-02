<?php

namespace App\Controllers\Api;

use App\Models\SupplierModel;
use CodeIgniter\HTTP\ResponseInterface;

class SupplierController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $model   = new SupplierModel();
        $filters = array_filter([
            'q'                => $this->queryString('q'),
            'status'           => $this->queryString('status'),
            'integration_type' => $this->queryString('integration_type'),
        ]);

        $rows = $model->withStats()
            ->applyFilters($filters)
            ->orderBy('suppliers.name')
            ->paginate($this->perPage());

        return $this->respond($this->paginated(array_map($this->present(...), $rows), $model->pager));
    }

    public function show(int $id): ResponseInterface
    {
        $supplier = (new SupplierModel())->withStats()->where('suppliers.id', $id)->first();

        if ($supplier === null) {
            return $this->failNotFound('Fornecedor não encontrado.');
        }

        return $this->respond(['data' => $this->present($supplier)]);
    }

    private function present(array $supplier): array
    {
        return [
            'id'                    => (int) $supplier['id'],
            'name'                  => $supplier['name'],
            'legal_name'            => $supplier['legal_name'],
            'document'              => $supplier['document'],
            'email'                 => $supplier['email'],
            'phone'                 => $supplier['phone'],
            'website'               => $supplier['website'],
            'integration_type'      => $supplier['integration_type'],
            'status'                => $supplier['status'],
            'priority'              => (int) $supplier['priority'],
            'default_shipping_days' => $supplier['default_shipping_days'] !== null ? (int) $supplier['default_shipping_days'] : null,
            'offers_count'          => (int) ($supplier['offers_count'] ?? 0),
            'last_offer_sync_at'    => $supplier['last_offer_sync_at'] ?? null,
            'created_at'            => $supplier['created_at'],
            'updated_at'            => $supplier['updated_at'],
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\SupplierIntegrationType;
use App\Enums\SupplierStatus;
use CodeIgniter\Model;

class SupplierModel extends Model
{
    protected $table         = 'suppliers';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'name', 'legal_name', 'document', 'email', 'phone', 'website',
        'integration_type', 'status', 'priority', 'default_shipping_days', 'safety_stock', 'notes',
    ];
    protected $validationMessages = [
        'document' => ['is_unique' => 'Já existe um fornecedor com este documento.'],
    ];

    protected function initialize(): void
    {
        $this->validationRules = [
            'id'                    => 'permit_empty|is_natural_no_zero',
            'name'                  => 'required|min_length[2]|max_length[120]',
            'legal_name'            => 'permit_empty|max_length[190]',
            'document'              => 'permit_empty|numeric|min_length[11]|max_length[14]|is_unique[suppliers.document,id,{id}]',
            'email'                 => 'permit_empty|valid_email|max_length[190]',
            'phone'                 => 'permit_empty|max_length[30]',
            'website'               => 'permit_empty|valid_url_strict[http,https]|max_length[255]',
            'integration_type'      => 'required|' . SupplierIntegrationType::inListRule(),
            'status'                => 'required|' . SupplierStatus::inListRule(),
            'priority'              => 'required|is_natural|less_than_equal_to[10]',
            'default_shipping_days' => 'permit_empty|is_natural|less_than_equal_to[365]',
            'safety_stock'          => 'permit_empty|is_natural|less_than_equal_to[65535]',
            'notes'                 => 'permit_empty|max_length[5000]',
        ];
    }

    /**
     * Inclui quantidade de ofertas e data da última sincronização de oferta.
     */
    public function withStats(): static
    {
        $this->select('suppliers.*, COUNT(supplier_offers.id) AS offers_count, MAX(supplier_offers.last_sync_at) AS last_offer_sync_at')
            ->join('supplier_offers', 'supplier_offers.supplier_id = suppliers.id', 'left')
            ->groupBy('suppliers.id');

        return $this;
    }

    public function applyFilters(array $filters): static
    {
        if (! empty($filters['q'])) {
            $this->groupStart()
                ->like('suppliers.name', $filters['q'])
                ->orLike('suppliers.legal_name', $filters['q'])
                ->orLike('suppliers.document', $filters['q'])
                ->groupEnd();
        }

        if (! empty($filters['status'])) {
            $this->where('suppliers.status', $filters['status']);
        }

        if (! empty($filters['integration_type'])) {
            $this->where('suppliers.integration_type', $filters['integration_type']);
        }

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function dropdownOptions(): array
    {
        $rows = $this->select('id, name')->orderBy('name')->findAll();

        return array_column($rows, 'name', 'id');
    }
}

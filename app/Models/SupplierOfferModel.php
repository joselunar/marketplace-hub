<?php

namespace App\Models;

use CodeIgniter\Model;

class SupplierOfferModel extends Model
{
    protected $table         = 'supplier_offers';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'supplier_id', 'product_id', 'supplier_sku', 'supplier_product_name', 'ean',
        'cost_price', 'stock_quantity', 'shipping_cost', 'shipping_days', 'active', 'last_sync_at',
    ];
    protected $validationRules = [
        'id'                    => 'permit_empty|is_natural_no_zero',
        'supplier_id'           => 'required|is_natural_no_zero|is_not_unique[suppliers.id]',
        'product_id'            => 'permit_empty|is_natural_no_zero|is_not_unique[products.id]',
        'supplier_sku'          => 'required|max_length[100]',
        'supplier_product_name' => 'required|max_length[255]',
        'ean'                   => 'permit_empty|numeric|min_length[8]|max_length[14]',
        'cost_price'            => 'required|decimal|greater_than[0]|less_than[10000000000000]',
        'stock_quantity'        => 'required|is_natural|less_than[2000000000]',
        'shipping_cost'         => 'permit_empty|decimal|greater_than_equal_to[0]|less_than[10000000000000]',
        'shipping_days'         => 'permit_empty|is_natural|less_than_equal_to[365]',
        'active'                => 'permit_empty|in_list[0,1]',
    ];
    protected $validationMessages = [
        'supplier_id' => ['is_not_unique' => 'Fornecedor inválido.'],
        'product_id'  => ['is_not_unique' => 'Produto inválido.'],
        'cost_price'  => ['greater_than' => 'O custo deve ser maior que zero.'],
    ];

    /**
     * Junta nome do fornecedor/produto para listagens.
     */
    public function withRelations(): static
    {
        $this->select(
            'supplier_offers.*, suppliers.name AS supplier_name, suppliers.status AS supplier_status, '
            . 'suppliers.default_shipping_days, products.name AS product_name, products.sku AS product_sku',
        )
            ->join('suppliers', 'suppliers.id = supplier_offers.supplier_id')
            ->join('products', 'products.id = supplier_offers.product_id', 'left');

        return $this;
    }

    public function applyFilters(array $filters): static
    {
        if (! empty($filters['q'])) {
            $this->groupStart()
                ->like('supplier_offers.supplier_sku', $filters['q'])
                ->orLike('supplier_offers.supplier_product_name', $filters['q'])
                ->orLike('products.name', $filters['q'])
                ->groupEnd();
        }

        if (! empty($filters['supplier_id'])) {
            $this->where('supplier_offers.supplier_id', (int) $filters['supplier_id']);
        }

        if (($filters['link'] ?? '') === 'linked') {
            $this->where('supplier_offers.product_id IS NOT NULL', null, false);
        } elseif (($filters['link'] ?? '') === 'unlinked') {
            $this->where('supplier_offers.product_id IS NULL', null, false);
        }

        if (($filters['status'] ?? '') === 'active') {
            $this->where('supplier_offers.active', 1);
        } elseif (($filters['status'] ?? '') === 'inactive') {
            $this->where('supplier_offers.active', 0);
        } elseif (($filters['status'] ?? '') === 'out_of_stock') {
            $this->where('supplier_offers.stock_quantity', 0);
        }

        return $this;
    }

    public function forProduct(int $productId): array
    {
        return $this->withRelations()
            ->where('supplier_offers.product_id', $productId)
            ->orderBy('supplier_offers.cost_price')
            ->findAll();
    }

    public function findBySupplierSku(int $supplierId, string $supplierSku): ?array
    {
        return $this->where('supplier_id', $supplierId)->where('supplier_sku', $supplierSku)->first();
    }
}

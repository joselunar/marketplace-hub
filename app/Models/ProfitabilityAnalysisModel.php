<?php

namespace App\Models;

use CodeIgniter\Model;

class ProfitabilityAnalysisModel extends Model
{
    protected $table         = 'profitability_analyses';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'product_id', 'supplier_id', 'supplier_offer_id', 'classification',
        'sale_price', 'cost_price', 'marketplace_fee_total', 'marketplace_fee_source',
        'seller_shipping_cost', 'seller_shipping_source', 'variable_costs_total',
        'contribution_margin', 'contribution_margin_pct', 'operating_result',
        'minimum_viable_price', 'break_even_units', 'classic_margin_pct', 'premium_margin_pct',
        'classic_fee_total', 'premium_fee_total', 'preferred_listing_type',
        'cost_reliability', 'unknown_costs', 'breakdown', 'block_reasons',
        'marketplace_category_id', 'quote_account_id', 'quoted_at', 'calculation_source',
    ];

    public function withProduct(): static
    {
        $this->select(
            'profitability_analyses.*, products.sku, products.name AS product_name, products.source_provider, '
            . 'products.origin_uf, products.sale_price AS product_sale_price, products.active, '
            . 'suppliers.name AS supplier_name, supplier_offers.suggested_price, supplier_offers.stock_quantity, '
            . 'supplier_offers.supplier_sku, supplier_offers.image_urls',
        )
            ->join('products', 'products.id = profitability_analyses.product_id')
            ->join('suppliers', 'suppliers.id = profitability_analyses.supplier_id', 'left')
            ->join('supplier_offers', 'supplier_offers.id = profitability_analyses.supplier_offer_id', 'left');

        return $this;
    }

    /**
     * @param array{q?: string, supplier_id?: string, classification?: string, category_id?: string, min_price?: string, max_price?: string} $filters
     */
    public function applyFilters(array $filters): static
    {
        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $this->groupStart()
                ->like('products.name', $q)
                ->orLike('products.sku', $q)
                ->orLike('supplier_offers.supplier_sku', $q)
                ->groupEnd();
        }

        if (! empty($filters['supplier_id']) && ctype_digit((string) $filters['supplier_id'])) {
            $this->where('profitability_analyses.supplier_id', (int) $filters['supplier_id']);
        }

        if (! empty($filters['classification'])) {
            $this->where('profitability_analyses.classification', $filters['classification']);
        }

        if (! empty($filters['category_id']) && ctype_digit((string) $filters['category_id'])) {
            $this->where('products.category_id', (int) $filters['category_id']);
        }

        if (! empty($filters['min_price']) && is_numeric($filters['min_price'])) {
            $this->where('profitability_analyses.sale_price >=', $filters['min_price']);
        }

        if (! empty($filters['max_price']) && is_numeric($filters['max_price'])) {
            $this->where('profitability_analyses.sale_price <=', $filters['max_price']);
        }

        return $this;
    }

    /**
     * @return array<string, int>
     */
    public function countsByClassification(?int $supplierId = null): array
    {
        $builder = $this->db->table($this->table)->select('classification, COUNT(*) AS total')->groupBy('classification');
        if ($supplierId !== null) {
            $builder->where('supplier_id', $supplierId);
        }

        $out = [
            'rentavel'         => 0,
            'margem_baixa'     => 0,
            'prejuizo'         => 0,
            'aguardando_dados' => 0,
            'total'            => 0,
        ];

        foreach ($builder->get()->getResultArray() as $row) {
            $key = (string) $row['classification'];
            if (isset($out[$key])) {
                $out[$key] = (int) $row['total'];
            }
            $out['total'] += (int) $row['total'];
        }

        return $out;
    }
}

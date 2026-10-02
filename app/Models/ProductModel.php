<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductModel extends Model
{
    /**
     * Subconsultas fixas (sem entrada do usuário) usadas em filtros e no dashboard.
     */
    public const HAS_OFFER_SQL = 'SELECT 1 FROM supplier_offers so WHERE so.product_id = products.id';

    public const HAS_STOCK_SQL = 'SELECT 1 FROM supplier_offers so JOIN suppliers s ON s.id = so.supplier_id '
        . "WHERE so.product_id = products.id AND so.active = 1 AND so.stock_quantity > 0 AND s.status = 'active'";

    protected $table         = 'products';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'sku', 'ean', 'name', 'slug', 'description', 'brand', 'category_id',
        'weight', 'height', 'width', 'length', 'safety_stock', 'active',
    ];
    protected $validationRules = [
        'id'          => 'permit_empty|is_natural_no_zero',
        'sku'         => 'required|max_length[60]|regex_match[/^[A-Za-z0-9._\-\/]+$/]|is_unique[products.sku,id,{id}]',
        'ean'         => 'permit_empty|numeric|min_length[8]|max_length[14]|is_unique[products.ean,id,{id}]',
        'name'        => 'required|min_length[3]|max_length[200]',
        'slug'        => 'required|max_length[220]|is_unique[products.slug,id,{id}]',
        'description' => 'permit_empty|max_length[20000]',
        'brand'       => 'permit_empty|max_length[100]',
        'category_id' => 'permit_empty|is_natural_no_zero|is_not_unique[categories.id]',
        'weight'      => 'permit_empty|decimal|greater_than_equal_to[0]',
        'height'      => 'permit_empty|decimal|greater_than_equal_to[0]',
        'width'       => 'permit_empty|decimal|greater_than_equal_to[0]',
        'length'      => 'permit_empty|decimal|greater_than_equal_to[0]',
        'safety_stock' => 'permit_empty|is_natural|less_than_equal_to[65535]',
        'active'      => 'permit_empty|in_list[0,1]',
    ];
    protected $validationMessages = [
        'sku'         => [
            'is_unique'   => 'Já existe um produto com este SKU.',
            'regex_match' => 'O SKU aceita apenas letras, números, ponto, hífen, underline e barra.',
        ],
        'ean'         => ['is_unique' => 'Já existe um produto com este EAN.'],
        'slug'        => ['is_unique' => 'Já existe um produto com este slug.'],
        'category_id' => ['is_not_unique' => 'Categoria inválida.'],
    ];

    /**
     * Agrega dados das ofertas para a listagem: quantidade de ofertas,
     * estoque somado das ofertas ativas e menor custo ativo.
     */
    public function withOfferStats(): static
    {
        $this->select(
            'products.*, categories.name AS category_name, '
            . 'COUNT(supplier_offers.id) AS offers_count, '
            . 'COALESCE(SUM(CASE WHEN supplier_offers.active = 1 AND suppliers.status = \'active\' THEN supplier_offers.stock_quantity ELSE 0 END), 0) AS total_stock, '
            . 'MIN(CASE WHEN supplier_offers.active = 1 AND suppliers.status = \'active\' THEN supplier_offers.cost_price END) AS min_cost, '
            . 'MAX(selected_supplier.name) AS selected_supplier_name, MAX(selected_offer.selection_score) AS selected_score',
        )
            ->join('categories', 'categories.id = products.category_id', 'left')
            ->join('supplier_offers', 'supplier_offers.product_id = products.id', 'left')
            ->join('suppliers', 'suppliers.id = supplier_offers.supplier_id', 'left')
            ->join('supplier_offers selected_offer', 'selected_offer.id = products.selected_offer_id', 'left')
            ->join('suppliers selected_supplier', 'selected_supplier.id = selected_offer.supplier_id', 'left')
            ->groupBy('products.id');

        return $this;
    }

    /**
     * @param array{q?: string, sku?: string, ean?: string, brand?: string, category_id?: int|string, status?: string, supplier?: string, selection?: string, stock?: string} $filters
     */
    public function applyFilters(array $filters): static
    {
        if (! empty($filters['q'])) {
            $this->like('products.name', $filters['q']);
        }

        if (! empty($filters['sku'])) {
            $this->like('products.sku', $filters['sku'], 'after');
        }

        if (! empty($filters['ean'])) {
            $this->where('products.ean', $filters['ean']);
        }

        if (! empty($filters['brand'])) {
            $this->like('products.brand', $filters['brand']);
        }

        if (! empty($filters['category_id'])) {
            $this->where('products.category_id', (int) $filters['category_id']);
        }

        if (($filters['status'] ?? '') === 'active') {
            $this->where('products.active', 1);
        } elseif (($filters['status'] ?? '') === 'inactive') {
            $this->where('products.active', 0);
        }

        if (($filters['supplier'] ?? '') === 'with') {
            $this->where('EXISTS (' . self::HAS_OFFER_SQL . ')', null, false);
        } elseif (($filters['supplier'] ?? '') === 'without') {
            $this->where('NOT EXISTS (' . self::HAS_OFFER_SQL . ')', null, false);
        }

        if (($filters['selection'] ?? '') === 'selected') {
            $this->where('products.selected_offer_id IS NOT NULL', null, false);
        } elseif (($filters['selection'] ?? '') === 'none') {
            $this->where('products.selected_offer_id IS NULL', null, false)
                ->where('EXISTS (' . self::HAS_OFFER_SQL . ')', null, false);
        }

        if (($filters['stock'] ?? '') === 'in') {
            $this->where('EXISTS (' . self::HAS_STOCK_SQL . ')', null, false);
        } elseif (($filters['stock'] ?? '') === 'out') {
            $this->where('NOT EXISTS (' . self::HAS_STOCK_SQL . ')', null, false);
        }

        return $this;
    }

    /**
     * Busca leve para autocomplete (SKU, EAN ou nome).
     */
    public function search(string $term, int $limit = 15): array
    {
        return $this->select('id, sku, ean, name')
            ->groupStart()
            ->like('sku', $term, 'after')
            ->orWhere('ean', $term)
            ->orLike('name', $term)
            ->groupEnd()
            ->orderBy('name')
            ->findAll($limit);
    }
}

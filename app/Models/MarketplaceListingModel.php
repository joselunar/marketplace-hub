<?php

namespace App\Models;

use CodeIgniter\Model;

class MarketplaceListingModel extends Model
{
    // Estados do Mercado Livre (GET /items) mais dois internos: pending e error.
    public const STATUS_PENDING        = 'pending';
    public const STATUS_ERROR          = 'error';
    public const STATUS_ACTIVE         = 'active';
    public const STATUS_PAUSED         = 'paused';
    public const STATUS_CLOSED         = 'closed';
    public const STATUS_UNDER_REVIEW   = 'under_review';
    public const STATUS_INACTIVE       = 'inactive';
    public const STATUS_PAYMENT        = 'payment_required';
    public const STATUS_NOT_YET_ACTIVE = 'not_yet_active';

    public const STATUS_LABELS = [
        self::STATUS_PENDING        => 'Enviando',
        self::STATUS_ERROR          => 'Falha na publicação',
        self::STATUS_ACTIVE         => 'Ativo',
        self::STATUS_PAUSED         => 'Pausado',
        self::STATUS_CLOSED         => 'Finalizado',
        self::STATUS_UNDER_REVIEW   => 'Em revisão',
        self::STATUS_INACTIVE       => 'Inativo',
        self::STATUS_PAYMENT        => 'Pagamento pendente',
        self::STATUS_NOT_YET_ACTIVE => 'Ainda não ativo',
    ];

    public const MODEL_CLASSIC       = 'classic';
    public const MODEL_USER_PRODUCTS = 'user_products';

    public const MODEL_LABELS = [
        self::MODEL_CLASSIC       => 'Clássico',
        self::MODEL_USER_PRODUCTS => 'User Products',
    ];

    protected $table         = 'marketplace_listings';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'marketplace_account_id', 'product_id', 'external_listing_id', 'external_product_id', 'selling_model',
        'title', 'family_name', 'category_id', 'listing_type', 'item_condition', 'currency_id', 'price',
        'available_quantity', 'status', 'sub_status', 'permalink', 'sale_fee_percentage', 'sale_fixed_fee',
        'sale_fee_amount', 'listing_fee_amount', 'attributes', 'last_error', 'published_by', 'published_at', 'last_sync_at',
    ];

    /**
     * Anúncio com produto, conta e oferta do fornecedor principal (custo e estoque atuais).
     */
    public function withDetails(): static
    {
        $this->select(
            'marketplace_listings.*, products.sku, products.name AS product_name, products.category_id AS product_category_id, '
            . 'products.desired_margin_percentage, products.minimum_margin_percentage, products.extra_cost, products.sale_price, '
            . 'products.suggested_price, products.price_status, products.active AS product_active, '
            . 'marketplace_accounts.name AS account_name, '
            . 'selected_offer.cost_price, selected_offer.shipping_cost, selected_offer.available_stock, '
            . 'selected_supplier.name AS supplier_name',
        )
            ->join('marketplace_accounts', 'marketplace_accounts.id = marketplace_listings.marketplace_account_id')
            ->join('products', 'products.id = marketplace_listings.product_id', 'left')
            ->join('supplier_offers selected_offer', 'selected_offer.id = products.selected_offer_id', 'left')
            ->join('suppliers selected_supplier', 'selected_supplier.id = selected_offer.supplier_id', 'left');

        return $this;
    }

    /**
     * @param array{q?: string, status?: string, account?: string} $filters
     */
    public function applyFilters(array $filters): static
    {
        if (! empty($filters['q'])) {
            $this->groupStart()
                ->like('products.name', $filters['q'])
                ->orLike('products.sku', $filters['q'], 'after')
                ->orWhere('marketplace_listings.external_listing_id', strtoupper($filters['q']))
                ->groupEnd();
        }

        if (! empty($filters['status']) && array_key_exists($filters['status'], self::STATUS_LABELS)) {
            $this->where('marketplace_listings.status', $filters['status']);
        }

        if (! empty($filters['account']) && ctype_digit($filters['account'])) {
            $this->where('marketplace_listings.marketplace_account_id', (int) $filters['account']);
        }

        return $this;
    }

    public function forProduct(int $productId): array
    {
        return $this->select('marketplace_listings.*, marketplace_accounts.name AS account_name')
            ->join('marketplace_accounts', 'marketplace_accounts.id = marketplace_listings.marketplace_account_id')
            ->where('marketplace_listings.product_id', $productId)
            ->orderBy('marketplace_listings.id')
            ->findAll();
    }
}

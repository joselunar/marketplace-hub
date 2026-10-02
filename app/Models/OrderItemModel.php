<?php

namespace App\Models;

use CodeIgniter\Model;

class OrderItemModel extends Model
{
    protected $table         = 'order_items';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'order_id', 'product_id', 'marketplace_listing_id', 'supplier_offer_id', 'supplier_id', 'external_item_id',
        'variation_id', 'title', 'seller_sku', 'quantity', 'unit_price', 'sale_fee', 'supplier_cost',
        'supplier_shipping', 'estimated_profit', 'allocation_note',
    ];

    /**
     * Itens com produto, anúncio e fornecedor reservado.
     */
    public function forOrder(int $orderId): array
    {
        return $this->select(
            'order_items.*, products.sku, products.name AS product_name, '
            . 'marketplace_listings.permalink, suppliers.name AS supplier_name, supplier_offers.supplier_sku',
        )
            ->join('products', 'products.id = order_items.product_id', 'left')
            ->join('marketplace_listings', 'marketplace_listings.id = order_items.marketplace_listing_id', 'left')
            ->join('suppliers', 'suppliers.id = order_items.supplier_id', 'left')
            ->join('supplier_offers', 'supplier_offers.id = order_items.supplier_offer_id', 'left')
            ->where('order_items.order_id', $orderId)
            ->orderBy('order_items.id')
            ->findAll();
    }
}

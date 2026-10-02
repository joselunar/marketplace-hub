<?php

namespace App\Models;

use CodeIgniter\Model;

class OrderModel extends Model
{
    // Status da order no Mercado Livre (documentação "Orders").
    public const STATUS_CONFIRMED          = 'confirmed';
    public const STATUS_PAYMENT_REQUIRED   = 'payment_required';
    public const STATUS_PAYMENT_IN_PROCESS = 'payment_in_process';
    public const STATUS_PARTIALLY_PAID     = 'partially_paid';
    public const STATUS_PAID               = 'paid';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
    public const STATUS_PENDING_CANCEL     = 'pending_cancel';
    public const STATUS_CANCELLED          = 'cancelled';
    public const STATUS_INVALID            = 'invalid';

    public const STATUS_LABELS = [
        self::STATUS_CONFIRMED          => 'Confirmado',
        self::STATUS_PAYMENT_REQUIRED   => 'Aguardando pagamento',
        self::STATUS_PAYMENT_IN_PROCESS => 'Pagamento em análise',
        self::STATUS_PARTIALLY_PAID     => 'Pago parcialmente',
        self::STATUS_PAID               => 'Pago',
        self::STATUS_PARTIALLY_REFUNDED => 'Reembolso parcial',
        self::STATUS_PENDING_CANCEL     => 'Cancelando',
        self::STATUS_CANCELLED          => 'Cancelado',
        self::STATUS_INVALID            => 'Inválido',
    ];

    /**
     * Status em que a venda está paga e o fornecedor pode ser acionado.
     */
    public const PAID_STATUSES = [self::STATUS_PAID, self::STATUS_PARTIALLY_REFUNDED];

    public const CANCELLED_STATUSES = [self::STATUS_CANCELLED, self::STATUS_INVALID, self::STATUS_PENDING_CANCEL];

    // Andamento interno do pedido junto aos fornecedores.
    public const FULFILLMENT_AWAITING_PAYMENT = 'awaiting_payment';
    public const FULFILLMENT_ATTENTION        = 'attention';
    public const FULFILLMENT_PENDING          = 'pending_supplier';
    public const FULFILLMENT_SENT             = 'sent';
    public const FULFILLMENT_COMPLETED        = 'completed';
    public const FULFILLMENT_CANCELLED        = 'cancelled';

    public const FULFILLMENT_LABELS = [
        self::FULFILLMENT_AWAITING_PAYMENT => 'Aguardando pagamento',
        self::FULFILLMENT_ATTENTION        => 'Requer atenção',
        self::FULFILLMENT_PENDING          => 'Aguardando envio ao fornecedor',
        self::FULFILLMENT_SENT             => 'Enviado ao fornecedor',
        self::FULFILLMENT_COMPLETED        => 'Entregue',
        self::FULFILLMENT_CANCELLED        => 'Cancelado',
    ];

    /**
     * Pedidos que dependem de alguém: fornecedor ainda não acionado ou pendência.
     */
    public const AWAITING_SUPPLIER = [self::FULFILLMENT_PENDING, self::FULFILLMENT_ATTENTION];

    protected $table         = 'orders';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'marketplace_id', 'marketplace_account_id', 'external_order_id', 'pack_id', 'shipping_id', 'buyer_external_id',
        'status', 'status_detail', 'currency_id', 'total_amount', 'paid_amount', 'marketplace_fees', 'fees_estimated',
        'shipping_amount', 'supplier_cost', 'estimated_profit', 'fulfillment_status', 'issue', 'fraud_risk', 'tags',
        'ordered_at', 'closed_at', 'external_updated_at', 'last_sync_at',
    ];

    public function withAccount(): static
    {
        $this->select(
            'orders.*, marketplace_accounts.name AS account_name, '
            . '(SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = orders.id) AS items_count, '
            . '(SELECT oi.title FROM order_items oi WHERE oi.order_id = orders.id ORDER BY oi.id LIMIT 1) AS first_item_title',
        )->join('marketplace_accounts', 'marketplace_accounts.id = orders.marketplace_account_id');

        return $this;
    }

    /**
     * @param array{q?: string, status?: string, fulfillment?: string, account?: string, period?: string} $filters
     */
    public function applyFilters(array $filters): static
    {
        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $this->groupStart()
                ->where('orders.external_order_id', $q)
                ->orWhere('orders.pack_id', $q)
                ->orWhere('EXISTS (SELECT 1 FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = orders.id AND (oi.title LIKE '
                    . $this->db->escape('%' . $this->db->escapeLikeString($q) . '%') . ' OR oi.external_item_id = ' . $this->db->escape(strtoupper($q))
                    . ' OR p.sku = ' . $this->db->escape($q) . '))', null, false)
                ->groupEnd();
        }

        if (! empty($filters['status']) && array_key_exists($filters['status'], self::STATUS_LABELS)) {
            $this->where('orders.status', $filters['status']);
        }

        if (! empty($filters['fulfillment'])) {
            if ($filters['fulfillment'] === 'awaiting_supplier') {
                $this->whereIn('orders.fulfillment_status', self::AWAITING_SUPPLIER);
            } elseif (array_key_exists($filters['fulfillment'], self::FULFILLMENT_LABELS)) {
                $this->where('orders.fulfillment_status', $filters['fulfillment']);
            }
        }

        if (! empty($filters['account']) && ctype_digit($filters['account'])) {
            $this->where('orders.marketplace_account_id', (int) $filters['account']);
        }

        $since = match ($filters['period'] ?? '') {
            'today' => date('Y-m-d 00:00:00'),
            '7d'    => date('Y-m-d H:i:s', strtotime('-7 days')),
            '30d'   => date('Y-m-d H:i:s', strtotime('-30 days')),
            default => null,
        };
        if ($since !== null) {
            $this->where('orders.ordered_at >=', $since);
        }

        return $this;
    }
}

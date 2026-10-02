<?php

namespace App\Models;

use CodeIgniter\Model;

class SupplierOrderModel extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_SHIPPED   = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_PENDING   => 'Pendente',
        self::STATUS_SENT      => 'Enviado ao fornecedor',
        self::STATUS_SHIPPED   => 'Despachado',
        self::STATUS_DELIVERED => 'Entregue',
        self::STATUS_CANCELLED => 'Cancelado',
    ];

    /**
     * Mudanças permitidas a partir de cada status (o envio ao fornecedor tem ação própria).
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING   => [self::STATUS_CANCELLED],
        self::STATUS_SENT      => [self::STATUS_SHIPPED, self::STATUS_DELIVERED, self::STATUS_CANCELLED],
        self::STATUS_SHIPPED   => [self::STATUS_DELIVERED, self::STATUS_CANCELLED],
        self::STATUS_DELIVERED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Já saiu das nossas mãos: o fornecedor recebeu o pedido.
     */
    public const PLACED_STATUSES = [self::STATUS_SENT, self::STATUS_SHIPPED, self::STATUS_DELIVERED];

    protected $table         = 'supplier_orders';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'order_id', 'supplier_id', 'external_supplier_order_id', 'status', 'cost', 'tracking_code', 'notes', 'sent_by', 'sent_at',
    ];

    public function forOrder(int $orderId): array
    {
        return $this->select('supplier_orders.*, suppliers.name AS supplier_name, suppliers.integration_type, users.name AS sent_by_name')
            ->join('suppliers', 'suppliers.id = supplier_orders.supplier_id')
            ->join('users', 'users.id = supplier_orders.sent_by', 'left')
            ->where('supplier_orders.order_id', $orderId)
            ->orderBy('supplier_orders.id')
            ->findAll();
    }
}

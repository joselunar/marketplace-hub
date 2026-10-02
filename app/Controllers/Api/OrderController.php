<?php

namespace App\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Models\OrderModel;
use App\Services\Orders\OrderProfitCalculator;
use CodeIgniter\HTTP\ResponseInterface;

class OrderController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $model   = new OrderModel();
        $filters = array_filter([
            'q'           => $this->queryString('q'),
            'status'      => $this->queryString('status'),
            'fulfillment' => $this->queryString('fulfillment'),
            'account'     => $this->queryString('account'),
            'period'      => $this->queryString('period'),
        ]);

        $rows = $model->withAccount()
            ->applyFilters($filters)
            ->orderBy('orders.ordered_at', 'DESC')
            ->orderBy('orders.id', 'DESC')
            ->paginate($this->perPage());

        return $this->respond($this->paginated(array_map($this->present(...), $rows), $model->pager));
    }

    public function show(int $id): ResponseInterface
    {
        $service = service('orders');

        try {
            $order = $service->findOrFail($id);
        } catch (BusinessRuleException $e) {
            return $this->failNotFound($e->getMessage());
        }

        $data = $this->present($order) + [
            'items' => array_map(static fn (array $item): array => [
                'external_item_id' => $item['external_item_id'],
                'variation_id'     => $item['variation_id'] !== '' ? $item['variation_id'] : null,
                'title'            => $item['title'],
                'product_id'       => $item['product_id'] !== null ? (int) $item['product_id'] : null,
                'sku'              => $item['sku'],
                'quantity'         => (int) $item['quantity'],
                'unit_price'       => $item['unit_price'],
                'sale_fee'         => $item['sale_fee'],
                'supplier_id'      => $item['supplier_id'] !== null ? (int) $item['supplier_id'] : null,
                'supplier_name'    => $item['supplier_name'],
                'supplier_cost'    => $item['supplier_cost'],
                'supplier_shipping' => $item['supplier_shipping'],
                'estimated_profit' => $item['estimated_profit'],
                'allocation_note'  => $item['allocation_note'],
            ], $service->items($id)),
            'supplier_orders' => array_map(static fn (array $so): array => [
                'id'                         => (int) $so['id'],
                'supplier_id'                => (int) $so['supplier_id'],
                'supplier_name'              => $so['supplier_name'],
                'status'                     => $so['status'],
                'cost'                       => $so['cost'],
                'external_supplier_order_id' => $so['external_supplier_order_id'],
                'tracking_code'              => $so['tracking_code'],
                'sent_at'                    => $so['sent_at'],
            ], $service->supplierOrders($id)),
        ];

        return $this->respond(['data' => $data]);
    }

    private function present(array $order): array
    {
        return [
            'id'                 => (int) $order['id'],
            'marketplace_account' => ['id' => (int) $order['marketplace_account_id'], 'name' => $order['account_name']],
            'external_order_id'  => $order['external_order_id'],
            'pack_id'            => $order['pack_id'],
            'status'             => $order['status'],
            'fulfillment_status' => $order['fulfillment_status'],
            'fraud_risk'         => (int) $order['fraud_risk'] === 1,
            'currency_id'        => $order['currency_id'],
            'total_amount'       => $order['total_amount'],
            'marketplace_fees'   => $order['marketplace_fees'],
            'fees_estimated'     => (int) $order['fees_estimated'] === 1,
            'shipping_amount'    => $order['shipping_amount'],
            'supplier_cost'      => $order['supplier_cost'],
            'estimated_profit'   => $order['estimated_profit'],
            'margin'             => (new OrderProfitCalculator())->margin($order['estimated_profit'], $order['total_amount']),
            'items_count'        => (int) ($order['items_count'] ?? 0),
            'issue'              => $order['issue'],
            'ordered_at'         => $order['ordered_at'],
            'updated_at'         => $order['updated_at'],
        ];
    }
}

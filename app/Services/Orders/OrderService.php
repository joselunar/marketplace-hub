<?php

namespace App\Services\Orders;

use App\DTO\Pricing\PricingConfig;
use App\DTO\Suppliers\OfferEvaluation;
use App\Exceptions\BusinessRuleException;
use App\Models\OrderItemModel;
use App\Models\OrderModel as Orders;
use App\Models\ProductModel;
use App\Models\SupplierOrderModel as SupplierOrders;
use App\Models\SyncLogModel;
use App\Services\Audit\AuditService;
use App\Services\Pricing\PricingService;
use App\Services\Suppliers\Connectors\SupplierConnectorFactory;
use App\Services\Suppliers\SupplierSelectionService;
use App\Services\Suppliers\SupplierService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use RuntimeException;
use Throwable;

/**
 * Pedidos de marketplace.
 *
 * Fluxo: pedido recebido → itens ligados ao produto (pelo anúncio ou pelo
 * SKU) → quando pago, fornecedores revalidados e um deles reservado por
 * item → lucro estimado → supplier_order pendente por fornecedor.
 *
 * Nada é comprado automaticamente: o operador usa "Enviar pedido ao
 * fornecedor". Só fornecedores com adapter próprio que implemente
 * create_order recebem o pedido por integração; os demais são registrados
 * como enviados manualmente.
 */
class OrderService
{
    private BaseConnection $db;

    public function __construct(
        private readonly Orders $orders = new Orders(),
        private readonly OrderItemModel $items = new OrderItemModel(),
        private readonly SupplierOrders $supplierOrders = new SupplierOrders(),
        private readonly ProductModel $products = new ProductModel(),
        private readonly OrderProfitCalculator $profit = new OrderProfitCalculator(),
        private readonly SupplierConnectorFactory $connectors = new SupplierConnectorFactory(),
        private readonly SyncLogModel $syncLogs = new SyncLogModel(),
        private readonly ?SupplierSelectionService $selection = null,
        private readonly ?PricingService $pricing = null,
        private readonly ?AuditService $audit = null,
    ) {
        $this->db = $this->orders->db;
    }

    /**
     * Grava o estado atual de um pedido normalizado pelo serviço do marketplace.
     *
     * Idempotente: a mesma order recebida várias vezes atualiza a mesma linha,
     * e uma versão mais antiga que a gravada (last_updated) é ignorada.
     *
     * @return array{order_id: int, created: bool, changed: bool}
     */
    public function ingest(array $account, array $data, ?int $userId = null): array
    {
        try {
            return $this->ingestLocked($account, $data, $userId);
        } catch (DatabaseException $e) {
            // Duas gravações simultâneas do mesmo pedido novo: a segunda bate
            // na chave única e, repetida, encontra a linha criada pela primeira.
            if ($this->orders->where('marketplace_account_id', (int) $account['id'])->where('external_order_id', $data['external_order_id'])->countAllResults() === 0) {
                throw $e;
            }

            return $this->ingestLocked($account, $data, $userId);
        }
    }

    public function findOrFail(int $id): array
    {
        $order = $this->orders->withAccount()->where('orders.id', $id)->first();

        if ($order === null) {
            throw new BusinessRuleException('Pedido não encontrado.');
        }

        return $order;
    }

    public function items(int $orderId): array
    {
        return $this->items->forOrder($orderId);
    }

    public function supplierOrders(int $orderId): array
    {
        return $this->supplierOrders->forOrder($orderId);
    }

    /**
     * Desfaz as reservas ainda não enviadas e escolhe os fornecedores de novo.
     */
    public function reallocate(int $orderId, ?int $userId = null): array
    {
        $this->transaction(function () use ($orderId, $userId): void {
            $this->reallocateLocked($orderId, $userId);
        });

        return $this->findOrFail($orderId);
    }

    private function reallocateLocked(int $orderId, ?int $userId): void
    {
        $order = $this->lockOrder($orderId);
        if (! in_array($order['status'], Orders::PAID_STATUSES, true)) {
            throw new BusinessRuleException('O pedido ainda não está pago no Mercado Livre.');
        }
        if ((int) $order['fraud_risk'] === 1) {
            throw new BusinessRuleException('Pedido com risco de fraude: não acione fornecedores.');
        }

        $placed = $this->supplierOrders->where('order_id', $orderId)->whereIn('status', SupplierOrders::PLACED_STATUSES)->countAllResults();
        if ($placed > 0) {
            throw new BusinessRuleException('Já existe pedido enviado ao fornecedor. Cancele-o antes de revalidar.');
        }

        $before = array_column($this->items->where('order_id', $orderId)->findAll(), 'supplier_id', 'id');

        $this->supplierOrders->where('order_id', $orderId)->delete();
        $this->items->builder()->where('order_id', $orderId)->update([
            'supplier_offer_id' => null, 'supplier_id' => null, 'supplier_cost' => null, 'supplier_shipping' => null,
            'estimated_profit'  => null, 'allocation_note' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->allocate($order, $userId);
        $this->recompute($orderId);

        $after = array_column($this->items->where('order_id', $orderId)->findAll(), 'supplier_id', 'id');
        $this->audit()->log('reallocate', 'order', $orderId, ['suppliers' => $this->supplierList($before)], ['suppliers' => $this->supplierList($after)], $userId);
    }

    /**
     * "Enviar pedido ao fornecedor". Por integração, se o fornecedor tiver
     * adapter com create_order; senão registra o envio feito pelo operador.
     */
    public function sendToSupplier(int $supplierOrderId, ?string $externalId, ?string $notes, ?int $userId): array
    {
        $externalId = $this->cleanText($externalId, 100);
        $notes      = $this->cleanText($notes, 1000);

        try {
            $via = $this->transaction(fn (): string => $this->sendLocked($supplierOrderId, $externalId, $notes, $userId));
        } catch (BusinessRuleException $e) {
            // Falha do adapter: registrada depois do rollback para não se perder com ele.
            if ($e->getPrevious() instanceof RuntimeException) {
                $row = $this->supplierOrders->find($supplierOrderId);
                $this->syncLog((int) ($row['supplier_id'] ?? 0), SyncLogModel::STATUS_ERROR, $e->getMessage());
            }

            throw $e;
        }

        return $this->supplierOrders->find($supplierOrderId) + ['via' => $via];
    }

    /**
     * @return string "integration" ou "manual"
     */
    private function sendLocked(int $supplierOrderId, ?string $externalId, ?string $notes, ?int $userId): string
    {
        $supplierOrder = $this->lockSupplierOrder($supplierOrderId);
        $order         = $this->lockOrder((int) $supplierOrder['order_id']);

        if ($supplierOrder['status'] !== SupplierOrders::STATUS_PENDING) {
            throw new BusinessRuleException('Este pedido ao fornecedor já foi enviado ou cancelado.');
        }
        if (! in_array($order['status'], Orders::PAID_STATUSES, true)) {
            throw new BusinessRuleException('O pedido não está pago no Mercado Livre; nada foi enviado ao fornecedor.');
        }
        if ((int) $order['fraud_risk'] === 1) {
            throw new BusinessRuleException('O Mercado Livre sinalizou risco de fraude neste pedido: não envie a mercadoria.');
        }

        $supplier = $this->db->table('suppliers')->where('id', (int) $supplierOrder['supplier_id'])->get()->getRowArray();
        if ($supplier === null) {
            throw new BusinessRuleException('Fornecedor não encontrado.');
        }
        $connector = $this->connectors->orderConnector($supplier);
        $via       = 'manual';

        if ($connector !== null) {
            try {
                $response = $connector->createOrder($this->connectorPayload($order, $supplierOrder));
            } catch (RuntimeException $e) {
                throw new BusinessRuleException(sprintf('Pedido %s não enviado ao fornecedor: %s', $order['external_order_id'], $e->getMessage()), 0, $e);
            }

            $externalId = $this->cleanText(is_scalar($response['external_order_id'] ?? null) ? (string) $response['external_order_id'] : null, 100) ?? $externalId;
            $via        = 'integration';
            $this->syncLog((int) $supplier['id'], SyncLogModel::STATUS_SUCCESS, 'Pedido ' . $order['external_order_id'] . ' enviado ao fornecedor' . ($externalId ? ' (' . $externalId . ').' : '.'));
        }

        $data = [
            'status'                     => SupplierOrders::STATUS_SENT,
            'external_supplier_order_id' => $externalId,
            'notes'                      => $notes,
            'sent_by'                    => $userId,
            'sent_at'                    => date('Y-m-d H:i:s'),
        ];
        $this->supplierOrders->update($supplierOrderId, $data);
        $this->recompute((int) $order['id']);
        $this->audit()->log('send_to_supplier', 'supplier_order', $supplierOrderId, ['status' => $supplierOrder['status']], $data + ['via' => $via, 'order' => $order['external_order_id']], $userId);

        return $via;
    }

    /**
     * Acompanhamento manual: despachado, entregue ou cancelado, rastreio e observações.
     */
    public function updateSupplierOrder(int $supplierOrderId, array $input, ?int $userId): array
    {
        $this->transaction(function () use ($supplierOrderId, $input, $userId): void {
            $this->updateSupplierOrderLocked($supplierOrderId, $input, $userId);
        });

        return $this->supplierOrders->find($supplierOrderId);
    }

    private function updateSupplierOrderLocked(int $supplierOrderId, array $input, ?int $userId): void
    {
        $current = $this->lockSupplierOrder($supplierOrderId);
        $status  = is_string($input['status'] ?? null) && $input['status'] !== '' ? $input['status'] : $current['status'];

        if ($status !== $current['status'] && ! in_array($status, SupplierOrders::TRANSITIONS[$current['status']] ?? [], true)) {
            throw new BusinessRuleException(sprintf(
                'Não é possível passar de "%s" para "%s".',
                SupplierOrders::STATUS_LABELS[$current['status']] ?? $current['status'],
                SupplierOrders::STATUS_LABELS[$status] ?? $status,
            ));
        }

        $data = ['status' => $status];
        foreach (['tracking_code' => 100, 'external_supplier_order_id' => 100, 'notes' => 1000] as $field => $max) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $this->cleanText(is_string($input[$field]) ? $input[$field] : null, $max);
            }
        }

        $changes = array_filter($data, static fn ($value, $field): bool => (string) ($current[$field] ?? '') !== (string) ($value ?? ''), ARRAY_FILTER_USE_BOTH);
        if ($changes === []) {
            return;
        }

        $this->supplierOrders->update($supplierOrderId, $changes);
        $this->recompute((int) $current['order_id']);
        $this->audit()->log('update', 'supplier_order', $supplierOrderId, array_intersect_key($current, $changes), $changes, $userId);
    }

    /**
     * @return array{order_id: int, created: bool, changed: bool}
     */
    private function ingestLocked(array $account, array $data, ?int $userId): array
    {
        return $this->transaction(fn (): array => $this->store($account, $data, $userId));
    }

    /**
     * @return array{order_id: int, created: bool, changed: bool}
     */
    private function store(array $account, array $data, ?int $userId): array
    {
        $existing = $this->db->query(
            'SELECT * FROM orders WHERE marketplace_account_id = ? AND external_order_id = ? FOR UPDATE',
            [(int) $account['id'], $data['external_order_id']],
        )->getRowArray();

        if ($existing !== null && $this->isStale($existing, $data)) {
            return ['order_id' => (int) $existing['id'], 'created' => false, 'changed' => false];
        }

        $columns = [
            'marketplace_id'         => (int) $account['marketplace_id'],
            'marketplace_account_id' => (int) $account['id'],
            'external_order_id'      => $data['external_order_id'],
            'pack_id'                => $data['pack_id'],
            'shipping_id'            => $data['shipping_id'],
            'buyer_external_id'      => $data['buyer_external_id'],
            'status'                 => $data['status'],
            'status_detail'          => $data['status_detail'],
            'currency_id'            => $data['currency_id'],
            'total_amount'           => $data['total_amount'],
            'paid_amount'            => $data['paid_amount'],
            'shipping_amount'        => $data['shipping_amount'],
            'fraud_risk'             => $data['fraud_risk'] ? 1 : 0,
            'tags'                   => $data['tags'] === [] ? null : mb_substr(implode(',', $data['tags']), 0, 500),
            'ordered_at'             => $data['ordered_at'],
            'closed_at'              => $data['closed_at'],
            'external_updated_at'    => $data['external_updated_at'],
            'last_sync_at'           => date('Y-m-d H:i:s'),
        ];

        if ($existing === null) {
            $this->orders->insert($columns);
            $orderId = (int) $this->orders->getInsertID();
            $changed = true;
        } else {
            $orderId = (int) $existing['id'];
            $changed = $this->differs($existing, $columns, ['status', 'status_detail', 'total_amount', 'paid_amount', 'shipping_amount', 'fraud_risk', 'tags', 'pack_id', 'shipping_id']);
            $this->orders->update($orderId, $columns);
        }

        $changed = $this->storeItems($orderId, (int) $account['id'], $data['items']) || $changed;

        $order = $this->orders->find($orderId);
        if (in_array($order['status'], Orders::PAID_STATUSES, true) && (int) $order['fraud_risk'] === 0) {
            $this->allocate($order, $userId);
        }
        if (in_array($order['status'], Orders::CANCELLED_STATUSES, true)) {
            $this->cancelPendingSupplierOrders($orderId);
        }
        $this->recompute($orderId);

        if ($existing === null) {
            $this->audit()->log('import', 'order', $orderId, null, ['external_order_id' => $data['external_order_id'], 'status' => $data['status'], 'total_amount' => $data['total_amount']], $userId);
        } elseif ($existing['status'] !== $data['status']) {
            $this->audit()->log('status_change', 'order', $orderId, ['status' => $existing['status']], ['status' => $data['status']], $userId);
        }

        return ['order_id' => $orderId, 'created' => $existing === null, 'changed' => $changed];
    }

    private function isStale(array $existing, array $data): bool
    {
        return $existing['external_updated_at'] !== null
            && $data['external_updated_at'] !== null
            && strtotime($data['external_updated_at']) < strtotime($existing['external_updated_at']);
    }

    /**
     * @return bool true se algum item foi criado ou mudou
     */
    private function storeItems(int $orderId, int $accountId, array $items): bool
    {
        $current = [];
        foreach ($this->items->where('order_id', $orderId)->findAll() as $row) {
            $current[$row['external_item_id'] . '|' . $row['variation_id']] = $row;
        }

        $changed = false;
        foreach ($items as $item) {
            $values = [
                'title'      => $item['title'],
                'seller_sku' => $item['seller_sku'],
                'quantity'   => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'sale_fee'   => $item['sale_fee'],
            ];
            $row = $current[$item['external_item_id'] . '|' . $item['variation_id']] ?? null;

            if ($row !== null) {
                if ($this->differs($row, $values, array_keys($values))) {
                    $this->items->update((int) $row['id'], $values);
                    $changed = true;
                }

                continue;
            }

            [$productId, $listingId] = $this->resolveProduct($accountId, $item);
            $this->items->insert($values + [
                'order_id'               => $orderId,
                'external_item_id'       => $item['external_item_id'],
                'variation_id'           => $item['variation_id'],
                'product_id'             => $productId,
                'marketplace_listing_id' => $listingId,
            ]);
            $changed = true;
        }

        return $changed;
    }

    /**
     * Produto do item: pelo anúncio publicado pelo sistema ou, na falta dele, pelo SKU do vendedor.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function resolveProduct(int $accountId, array $item): array
    {
        $listing = $this->db->table('marketplace_listings')
            ->select('id, product_id')
            ->where('marketplace_account_id', $accountId)
            ->where('external_listing_id', $item['external_item_id'])
            ->get()
            ->getRowArray();

        if ($listing !== null && $listing['product_id'] !== null) {
            return [(int) $listing['product_id'], (int) $listing['id']];
        }

        $product = $item['seller_sku'] !== null ? $this->products->select('id')->where('sku', $item['seller_sku'])->first() : null;

        return [$product !== null ? (int) $product['id'] : null, $listing !== null ? (int) $listing['id'] : null];
    }

    /**
     * Revalida os fornecedores e reserva um por item ainda sem fornecedor:
     * o melhor colocado no ranking com estoque seguro para a quantidade,
     * descontado o que já está reservado em outros pedidos não enviados.
     */
    private function allocate(array $order, ?int $userId): void
    {
        $orderId = (int) $order['id'];
        $pending = $this->items->where('order_id', $orderId)->where('supplier_offer_id', null)->where('product_id IS NOT NULL', null, false)->findAll();

        foreach ($pending as $item) {
            $quantity = (int) $item['quantity'];
            $result   = ($this->selection ?? service('supplierSelection'))->select((int) $item['product_id'], SupplierSelectionService::TRIGGER_ORDER, $userId);
            $eligible = $result->eligible();
            $chosen   = null;
            $largest  = 0;

            foreach ($eligible as $offer) {
                $free    = $offer->availableStock - $this->reservedQuantity($offer->offerId, $orderId);
                $largest = max($largest, $free);
                if ($free >= $quantity) {
                    $chosen = $offer;
                    break;
                }
            }

            if ($chosen === null) {
                $this->items->update((int) $item['id'], ['allocation_note' => $eligible === []
                    ? 'Nenhum fornecedor elegível para o produto.'
                    : sprintf('Nenhum fornecedor com estoque para %d unidade(s) (maior estoque livre: %d).', $quantity, max(0, $largest))]);

                continue;
            }

            $this->items->update((int) $item['id'], [
                'supplier_offer_id' => $chosen->offerId,
                'supplier_id'       => $chosen->supplierId,
                'supplier_cost'     => $chosen->costPrice,
                'supplier_shipping' => $chosen->shippingCost,
                'allocation_note'   => $chosen->rank === 1 ? null : $this->reserveNote($chosen, $quantity),
            ]);
        }

        $this->syncSupplierOrders($orderId);
    }

    /**
     * "item 3: fornecedor 2, item 4: nenhum", para o log de auditoria.
     *
     * @param array<int|string, int|string|null> $byItem
     */
    private function supplierList(array $byItem): string
    {
        $parts = [];
        foreach ($byItem as $itemId => $supplierId) {
            $parts[] = sprintf('item %d: %s', $itemId, $supplierId === null ? 'nenhum' : 'fornecedor ' . $supplierId);
        }

        return implode(', ', $parts);
    }

    private function reserveNote(OfferEvaluation $offer, int $quantity): string
    {
        return sprintf('%s (%s) escolhido: os fornecedores à frente não tinham estoque livre para %d unidade(s).', $offer->supplierName, $offer->positionLabel(), $quantity);
    }

    /**
     * Unidades da oferta reservadas em pedidos pagos cujo pedido ao fornecedor ainda não saiu.
     */
    private function reservedQuantity(int $offerId, int $exceptOrderId): int
    {
        $row = $this->db->table('order_items oi')
            ->select('COALESCE(SUM(oi.quantity), 0) AS reserved')
            ->join('supplier_orders so', 'so.order_id = oi.order_id AND so.supplier_id = oi.supplier_id')
            ->where('oi.supplier_offer_id', $offerId)
            ->where('oi.order_id !=', $exceptOrderId)
            ->where('so.status', SupplierOrders::STATUS_PENDING)
            ->get()
            ->getRowArray();

        return (int) ($row['reserved'] ?? 0);
    }

    /**
     * Um supplier_order pendente por fornecedor reservado, com o custo dos itens.
     */
    private function syncSupplierOrders(int $orderId): void
    {
        $totals = [];
        foreach ($this->items->where('order_id', $orderId)->where('supplier_id IS NOT NULL', null, false)->findAll() as $item) {
            $line = bcmul(bcadd((string) $item['supplier_cost'], (string) $item['supplier_shipping'], 2), (string) $item['quantity'], 2);
            $totals[(int) $item['supplier_id']] = bcadd($totals[(int) $item['supplier_id']] ?? '0.00', $line, 2);
        }

        $existing = array_column($this->supplierOrders->where('order_id', $orderId)->findAll(), null, 'supplier_id');

        foreach ($totals as $supplierId => $cost) {
            $row = $existing[$supplierId] ?? null;

            if ($row === null) {
                $this->supplierOrders->insert(['order_id' => $orderId, 'supplier_id' => $supplierId, 'status' => SupplierOrders::STATUS_PENDING, 'cost' => $cost]);
            } elseif ($row['status'] === SupplierOrders::STATUS_PENDING && bccomp((string) $row['cost'], $cost, 2) !== 0) {
                $this->supplierOrders->update((int) $row['id'], ['cost' => $cost]);
            }
        }
    }

    private function cancelPendingSupplierOrders(int $orderId): void
    {
        $this->supplierOrders->builder()
            ->where('order_id', $orderId)
            ->where('status', SupplierOrders::STATUS_PENDING)
            ->update(['status' => SupplierOrders::STATUS_CANCELLED, 'notes' => 'Cancelado automaticamente: venda cancelada no Mercado Livre.', 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Tarifas, custo, lucro estimado e andamento do pedido.
     */
    private function recompute(int $orderId): void
    {
        $order   = $this->orders->find($orderId);
        $items   = $this->items->where('order_id', $orderId)->findAll();
        $config  = ($this->pricing ?? service('pricing'))->config();
        $extras  = $this->productExtraCosts($items);
        $fees    = '0.00';
        $cost    = null;
        $profit  = '0.00';
        $missing = false;
        $estimated = false;

        foreach ($items as $item) {
            $fee = $item['sale_fee'];
            if ($fee === null) {
                $fee       = $this->profit->estimatedFee((string) $item['unit_price'], (int) $item['quantity'], $this->listingFees($item), $config);
                $estimated = true;
            }
            $fees = bcadd($fees, (string) $fee, 2);

            if ($item['supplier_id'] === null) {
                $missing = true;
                if ($item['estimated_profit'] !== null) {
                    $this->items->update((int) $item['id'], ['estimated_profit' => null]);
                }

                continue;
            }

            $line = $this->profit->itemProfit(
                (string) $item['unit_price'],
                (int) $item['quantity'],
                (string) $fee,
                (string) $item['supplier_cost'],
                (string) $item['supplier_shipping'],
                $extras[(int) $item['product_id']] ?? '0.00',
                $config,
            );
            $cost   = bcadd($cost ?? '0.00', bcmul(bcadd((string) $item['supplier_cost'], (string) $item['supplier_shipping'], 2), (string) $item['quantity'], 2), 2);
            $profit = bcadd($profit, $line, 2);

            if ((string) $item['estimated_profit'] !== $line) {
                $this->items->update((int) $item['id'], ['estimated_profit' => $line]);
            }
        }

        [$fulfillment, $issue] = $this->fulfillment($order, $items);

        $this->orders->update($orderId, [
            'marketplace_fees'   => $fees,
            'fees_estimated'     => $estimated ? 1 : 0,
            'supplier_cost'      => $cost,
            'estimated_profit'   => $missing || $items === [] ? null : $profit,
            'fulfillment_status' => $fulfillment,
            'issue'              => $issue === null ? null : mb_substr($issue, 0, 500),
        ]);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function fulfillment(array $order, array $items): array
    {
        $supplierOrders = $this->supplierOrders->where('order_id', (int) $order['id'])->findAll();
        $statuses       = array_column($supplierOrders, 'status');
        $active         = array_values(array_filter($statuses, static fn (string $s): bool => $s !== SupplierOrders::STATUS_CANCELLED));

        if (in_array($order['status'], Orders::CANCELLED_STATUSES, true)) {
            $placed = array_intersect($active, SupplierOrders::PLACED_STATUSES);

            return $placed === []
                ? [Orders::FULFILLMENT_CANCELLED, null]
                : [Orders::FULFILLMENT_ATTENTION, 'Venda cancelada no Mercado Livre com pedido já enviado ao fornecedor: cancele com o fornecedor e atualize aqui.'];
        }

        if (! in_array($order['status'], Orders::PAID_STATUSES, true)) {
            return [Orders::FULFILLMENT_AWAITING_PAYMENT, null];
        }

        if ((int) $order['fraud_risk'] === 1) {
            return [Orders::FULFILLMENT_ATTENTION, 'O Mercado Livre sinalizou risco de fraude (fraud_risk_detected): não envie a mercadoria e cancele a venda.'];
        }

        $issues = [];
        foreach ($items as $item) {
            if ($item['product_id'] === null) {
                $issues[] = sprintf('"%s" não está ligado a nenhum produto (anúncio de fora do sistema e SKU não encontrado).', $item['title']);
            } elseif ($item['supplier_id'] === null) {
                $issues[] = sprintf('"%s": %s', $item['title'], $item['allocation_note'] ?? 'sem fornecedor reservado.');
            }
        }
        if ($issues !== []) {
            return [Orders::FULFILLMENT_ATTENTION, implode(' ', $issues)];
        }

        if ($active === []) {
            return [Orders::FULFILLMENT_ATTENTION, 'Os pedidos ao fornecedor foram cancelados. Revalide os fornecedores para reservar de novo.'];
        }
        if (in_array(SupplierOrders::STATUS_PENDING, $active, true)) {
            return [Orders::FULFILLMENT_PENDING, null];
        }
        if (array_diff($active, [SupplierOrders::STATUS_DELIVERED]) === []) {
            return [Orders::FULFILLMENT_COMPLETED, null];
        }

        return [Orders::FULFILLMENT_SENT, null];
    }

    /**
     * @return array<int, string> custo extra por unidade de cada produto
     */
    private function productExtraCosts(array $items): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $i) => $i['product_id'] !== null ? (int) $i['product_id'] : null, $items))));
        if ($ids === []) {
            return [];
        }

        $extras = [];
        foreach ($this->products->select('id, extra_cost')->whereIn('id', $ids)->findAll() as $product) {
            $extras[(int) $product['id']] = $product['extra_cost'] !== null ? (string) $product['extra_cost'] : '0.00';
        }

        return $extras;
    }

    private function listingFees(array $item): ?array
    {
        if ($item['marketplace_listing_id'] === null) {
            return null;
        }

        return $this->db->table('marketplace_listings')
            ->select('sale_fee_percentage, sale_fixed_fee')
            ->where('id', (int) $item['marketplace_listing_id'])
            ->get()
            ->getRowArray();
    }

    /**
     * Dados entregues ao adapter do fornecedor. O endereço de entrega não é
     * guardado pelo sistema; o adapter que precisar dele consulta o marketplace.
     */
    private function connectorPayload(array $order, array $supplierOrder): array
    {
        $items = $this->items->select('order_items.quantity, order_items.supplier_cost, supplier_offers.supplier_sku')
            ->join('supplier_offers', 'supplier_offers.id = order_items.supplier_offer_id', 'left')
            ->where('order_items.order_id', (int) $order['id'])
            ->where('order_items.supplier_id', (int) $supplierOrder['supplier_id'])
            ->findAll();

        return [
            'reference'         => 'MH-' . $order['id'] . '-' . $supplierOrder['id'],
            'external_order_id' => $order['external_order_id'],
            'shipping_id'       => $order['shipping_id'],
            'items'             => array_map(static fn (array $i): array => [
                'supplier_sku' => $i['supplier_sku'],
                'quantity'     => (int) $i['quantity'],
                'unit_cost'    => $i['supplier_cost'],
            ], $items),
        ];
    }

    /**
     * Executa em transação; qualquer exceção (inclusive regra de negócio) desfaz tudo.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        $this->db->transException(true)->transStart();

        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        $this->db->transComplete();

        return $result;
    }

    private function lockOrder(int $orderId): array
    {
        $order = $this->db->query('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId])->getRowArray();

        if ($order === null) {
            throw new BusinessRuleException('Pedido não encontrado.');
        }

        return $order;
    }

    private function lockSupplierOrder(int $supplierOrderId): array
    {
        $row = $this->db->query('SELECT * FROM supplier_orders WHERE id = ? FOR UPDATE', [$supplierOrderId])->getRowArray();

        if ($row === null) {
            throw new BusinessRuleException('Pedido ao fornecedor não encontrado.');
        }

        return $row;
    }

    /**
     * @param list<string> $fields
     */
    private function differs(array $before, array $after, array $fields): bool
    {
        foreach ($fields as $field) {
            if ((string) ($before[$field] ?? '') !== (string) ($after[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }

    private function cleanText(?string $value, int $max): ?string
    {
        $value = $value === null ? '' : trim(strip_tags($value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function syncLog(int $supplierId, string $status, string $message): void
    {
        $this->syncLogs->insert([
            'integration_type' => SupplierService::SYNC_INTEGRATION_TYPE,
            'integration_id'   => $supplierId,
            'action'           => 'create_order',
            'status'           => $status,
            'message'          => mb_substr($message, 0, 1000),
            'started_at'       => date('Y-m-d H:i:s'),
            'finished_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    private function audit(): AuditService
    {
        return $this->audit ?? service('audit');
    }
}

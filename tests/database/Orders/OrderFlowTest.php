<?php

namespace Tests\Database\Orders;

use App\DTO\MercadoLivre\TokenSet;
use App\Exceptions\BusinessRuleException;
use App\Models\OrderModel;
use App\Models\SupplierOrderModel;
use App\Models\WebhookEventModel;
use App\Services\Marketplaces\MarketplaceAccountService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreApiClient;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreAuthService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreNotificationService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreOrderService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreService;
use App\Services\Orders\OrderService;
use App\Services\Suppliers\SupplierOfferService;
use Config\MercadoLivre;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeMercadoLivreTransport;

/**
 * Configuração padrão: imposto 6%, estoque de segurança 2. Venda de
 * R$ 200 com tarifa de R$ 24 e custo 100: 200 − 24 − 100 − 12 = 64.
 */
final class OrderFlowTest extends DatabaseTestCase
{
    private FakeMercadoLivreTransport $transport;
    private MercadoLivreOrderService $meliOrders;
    private MercadoLivreNotificationService $notifications;
    private OrderService $orders;
    private array $account;
    private int $productId;
    private int $alpha;
    private int $beta;

    protected function setUp(): void
    {
        parent::setUp();
        service('settings')->seedDefaults();

        $config               = new MercadoLivre();
        $config->clientId     = '5503910054141466';
        $config->clientSecret = 'segredo-do-app';
        $config->redirectUri  = 'http://localhost:8080/marketplaces/mercadolivre/callback';

        $this->transport = new FakeMercadoLivreTransport();
        $api             = new MercadoLivreApiClient($config, $this->transport);
        $accounts        = new MarketplaceAccountService();
        $meli            = new MercadoLivreService($config, $api, new MercadoLivreAuthService($config, $api, $accounts), $accounts);

        $this->orders        = new OrderService();
        $this->meliOrders    = new MercadoLivreOrderService($meli, $this->orders);
        $this->notifications = new MercadoLivreNotificationService($config, $this->meliOrders);

        $connection    = $accounts->storeConnection('mercadolivre', TokenSet::fromResponse(FakeMercadoLivreTransport::tokenResponse()), 'LOJA_TESTE', 'MLB', null);
        $this->account = $meli->resolveAccount($connection['id']);

        $this->productId = $this->createProduct(['sku' => 'FONE-01', 'name' => 'Fone Lunar X1']);
        $this->alpha     = $this->createSupplier(['name' => 'Alpha', 'priority' => 1, 'default_shipping_days' => 2]);
        $this->beta      = $this->createSupplier(['name' => 'Beta', 'priority' => 9, 'default_shipping_days' => 2]);
        $offers          = new SupplierOfferService();
        $offers->create(['supplier_id' => $this->alpha, 'product_id' => $this->productId, 'supplier_sku' => 'A-1', 'supplier_product_name' => 'Fone', 'cost_price' => '100', 'stock_quantity' => '7', 'shipping_cost' => '0', 'active' => '1']);
        $offers->create(['supplier_id' => $this->beta, 'product_id' => $this->productId, 'supplier_sku' => 'B-1', 'supplier_product_name' => 'Fone', 'cost_price' => '110', 'stock_quantity' => '7', 'shipping_cost' => '5', 'active' => '1']);
    }

    public function testPaidOrderReservesBestSupplierAndEstimatesProfit(): void
    {
        $this->routeOrder($this->mlOrder('2000000001'));

        $result = $this->meliOrders->import((int) $this->account['id'], '2000000001');

        $this->assertTrue($result['created']);
        $order = $this->orders->findOrFail($result['order_id']);
        $this->assertSame(OrderModel::FULFILLMENT_PENDING, $order['fulfillment_status']);
        $this->assertSame('200.00', $order['total_amount']);
        $this->assertSame('24.00', $order['marketplace_fees']);
        $this->assertSame('0', (string) $order['fees_estimated']);
        $this->assertSame('100.00', $order['supplier_cost']);
        $this->assertSame('64.00', $order['estimated_profit']);
        $this->assertSame('123', $order['buyer_external_id']);

        $item = $this->orders->items($result['order_id'])[0];
        $this->assertSame($this->productId, (int) $item['product_id'], 'ligado pelo seller_sku');
        $this->assertSame($this->alpha, (int) $item['supplier_id']);

        $supplierOrders = $this->orders->supplierOrders($result['order_id']);
        $this->assertCount(1, $supplierOrders);
        $this->assertSame(SupplierOrderModel::STATUS_PENDING, $supplierOrders[0]['status']);
        $this->assertSame('100.00', $supplierOrders[0]['cost']);
    }

    public function testReimportIsIdempotentAndIgnoresOlderVersion(): void
    {
        $this->routeOrder($this->mlOrder('2000000001', ['last_updated' => '2026-10-02T10:00:00.000-03:00']));
        $first = $this->meliOrders->import((int) $this->account['id'], '2000000001');

        $second = $this->meliOrders->import((int) $this->account['id'], '2000000001');
        $this->assertFalse($second['created']);
        $this->assertFalse($second['changed']);

        $this->transport->routesReset('GET', '/orders/2000000001');
        $this->routeOrder($this->mlOrder('2000000001', ['status' => 'cancelled', 'last_updated' => '2026-10-02T09:00:00.000-03:00']));
        $this->meliOrders->import((int) $this->account['id'], '2000000001');

        $this->assertSame(1, $this->db->table('orders')->countAllResults());
        $this->assertSame(1, $this->db->table('order_items')->countAllResults());
        $this->assertSame(1, $this->db->table('supplier_orders')->countAllResults());
        $this->assertSame('paid', $this->orders->findOrFail($first['order_id'])['status'], 'versão mais antiga não sobrescreve');
    }

    public function testReservedStockMovesNextOrderToBackupSupplier(): void
    {
        // Alpha: 7 em estoque − 2 de segurança = 5 livres.
        $this->routeOrder($this->mlOrder('2000000001', ['quantity' => 4]));
        $this->meliOrders->import((int) $this->account['id'], '2000000001');

        $this->routeOrder($this->mlOrder('2000000002', ['quantity' => 3]));
        $second = $this->meliOrders->import((int) $this->account['id'], '2000000002');

        $item = $this->orders->items($second['order_id'])[0];
        $this->assertSame($this->beta, (int) $item['supplier_id'], 'só 1 unidade livre no Alpha');
        $this->assertStringContainsString('Beta', (string) $item['allocation_note']);
    }

    public function testUnpaidOrderWaitsAndUnknownItemNeedsAttention(): void
    {
        $this->routeOrder($this->mlOrder('2000000001', ['status' => 'payment_required', 'sale_fee' => null]));
        $unpaid = $this->meliOrders->import((int) $this->account['id'], '2000000001');
        $order  = $this->orders->findOrFail($unpaid['order_id']);

        $this->assertSame(OrderModel::FULFILLMENT_AWAITING_PAYMENT, $order['fulfillment_status']);
        $this->assertSame(0, $this->db->table('supplier_orders')->countAllResults());
        $this->assertSame('1', (string) $order['fees_estimated'], 'sem sale_fee, tarifa estimada');

        $this->routeOrder($this->mlOrder('2000000002', ['seller_sku' => 'NAO-EXISTE']));
        $unknown = $this->meliOrders->import((int) $this->account['id'], '2000000002');
        $order   = $this->orders->findOrFail($unknown['order_id']);

        $this->assertSame(OrderModel::FULFILLMENT_ATTENTION, $order['fulfillment_status']);
        $this->assertNull($order['estimated_profit']);
        $this->assertStringContainsString('não está ligado a nenhum produto', (string) $order['issue']);
    }

    public function testFraudOrderIsNotAllocatedAndCannotBeSent(): void
    {
        $this->routeOrder($this->mlOrder('2000000001', ['tags' => ['paid', 'fraud_risk_detected']]));
        $result = $this->meliOrders->import((int) $this->account['id'], '2000000001');

        $order = $this->orders->findOrFail($result['order_id']);
        $this->assertSame('1', (string) $order['fraud_risk']);
        $this->assertSame(OrderModel::FULFILLMENT_ATTENTION, $order['fulfillment_status']);
        $this->assertSame(0, $this->db->table('supplier_orders')->countAllResults());

        $this->expectException(BusinessRuleException::class);
        $this->orders->reallocate($result['order_id'], null);
    }

    public function testCancellationCancelsPendingSupplierOrder(): void
    {
        $this->routeOrder($this->mlOrder('2000000001', ['last_updated' => '2026-10-02T10:00:00.000-03:00']));
        $result = $this->meliOrders->import((int) $this->account['id'], '2000000001');

        $this->transport->routesReset('GET', '/orders/2000000001');
        $this->routeOrder($this->mlOrder('2000000001', ['status' => 'cancelled', 'last_updated' => '2026-10-02T11:00:00.000-03:00']));
        $this->meliOrders->import((int) $this->account['id'], '2000000001');

        $this->assertSame(SupplierOrderModel::STATUS_CANCELLED, $this->orders->supplierOrders($result['order_id'])[0]['status']);
        $this->assertSame(OrderModel::FULFILLMENT_CANCELLED, $this->orders->findOrFail($result['order_id'])['fulfillment_status']);
    }

    public function testSendToSupplierManuallyAndTrackUntilDelivered(): void
    {
        $user = $this->createUser('operator');
        $this->routeOrder($this->mlOrder('2000000001'));
        $result        = $this->meliOrders->import((int) $this->account['id'], '2000000001');
        $supplierOrder = $this->orders->supplierOrders($result['order_id'])[0];

        $sent = $this->orders->sendToSupplier((int) $supplierOrder['id'], 'PED-77', '<b>Pago no Pix</b>', $user['id']);

        $this->assertSame('manual', $sent['via']);
        $this->assertSame(SupplierOrderModel::STATUS_SENT, $sent['status']);
        $this->assertSame('PED-77', $sent['external_supplier_order_id']);
        $this->assertSame('Pago no Pix', $sent['notes'], 'HTML removido');
        $this->assertSame(OrderModel::FULFILLMENT_SENT, $this->orders->findOrFail($result['order_id'])['fulfillment_status']);
        $this->assertSame(1, $this->db->table('audit_logs')->where('action', 'send_to_supplier')->countAllResults());

        try {
            $this->orders->sendToSupplier((int) $supplierOrder['id'], null, null, $user['id']);
            $this->fail('Envio repetido deveria ser recusado.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('já foi enviado', $e->getMessage());
        }

        try {
            $this->orders->reallocate($result['order_id'], $user['id']);
            $this->fail('Revalidar com pedido enviado deveria ser recusado.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Já existe pedido enviado', $e->getMessage());
        }

        $this->orders->updateSupplierOrder((int) $supplierOrder['id'], ['status' => 'shipped', 'tracking_code' => 'BR123'], $user['id']);
        $this->orders->updateSupplierOrder((int) $supplierOrder['id'], ['status' => 'delivered'], $user['id']);

        $this->assertSame('BR123', $this->orders->supplierOrders($result['order_id'])[0]['tracking_code']);
        $this->assertSame(OrderModel::FULFILLMENT_COMPLETED, $this->orders->findOrFail($result['order_id'])['fulfillment_status']);

        $this->expectException(BusinessRuleException::class);
        $this->orders->updateSupplierOrder((int) $supplierOrder['id'], ['status' => 'pending'], $user['id']);
    }

    public function testFailedBusinessRuleRollsBackEverything(): void
    {
        $this->routeOrder($this->mlOrder('2000000001'));
        $result        = $this->meliOrders->import((int) $this->account['id'], '2000000001');
        $supplierOrder = $this->orders->supplierOrders($result['order_id'])[0];
        $this->db->table('orders')->where('id', $result['order_id'])->update(['fraud_risk' => 1]);

        try {
            $this->orders->sendToSupplier((int) $supplierOrder['id'], 'X', null, null);
            $this->fail('Pedido com fraude não pode ser enviado.');
        } catch (BusinessRuleException) {
        }

        $this->assertSame(SupplierOrderModel::STATUS_PENDING, $this->orders->supplierOrders($result['order_id'])[0]['status']);
        $this->assertSame(0, $this->db->transDepth, 'transação encerrada');
    }

    public function testReallocateUsesCurrentStock(): void
    {
        $this->routeOrder($this->mlOrder('2000000001'));
        $result = $this->meliOrders->import((int) $this->account['id'], '2000000001');
        $this->db->table('supplier_offers')->where('supplier_id', $this->alpha)->update(['stock_quantity' => 0]);

        $this->orders->reallocate($result['order_id'], null);

        $supplierOrders = $this->orders->supplierOrders($result['order_id']);
        $this->assertCount(1, $supplierOrders);
        $this->assertSame($this->beta, (int) $supplierOrders[0]['supplier_id']);
        $this->assertSame('115.00', $supplierOrders[0]['cost']);
    }

    public function testImportRecentPagesThroughSearch(): void
    {
        $this->transport->on('GET', '/orders/search', 200, [
            'results' => [$this->mlOrder('2000000001'), $this->mlOrder('2000000002'), ['id' => 'lixo']],
            'paging'  => ['total' => 3, 'offset' => 0, 'limit' => 50],
        ]);

        $stats = $this->meliOrders->importRecent((int) $this->account['id'], 7);

        $this->assertSame(2, $stats['created']);
        $this->assertSame(1, $stats['failed']);
        $url = $this->transport->requestsTo('GET', '/orders/search')[0]['url'];
        $this->assertStringContainsString('seller=123456', $url);
        $this->assertStringContainsString('sort=date_desc', $url);
        $this->assertStringContainsString('order.date_last_updated.from=', $url);
    }

    public function testNormalizeRejectsOrderFromAnotherSeller(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->meliOrders->normalize($this->mlOrder('2000000001', ['seller' => 999]), $this->account);
    }

    public function testNotificationIsStoredOnceAndProcessedLater(): void
    {
        $payload = $this->notification('/orders/2000000001');

        $first  = $this->notifications->receive($payload);
        $repeat = $this->notifications->receive($payload + ['attempts' => 2]);

        $this->assertSame(200, $first['status']);
        $this->assertNotNull($first['event_id']);
        $this->assertNull($repeat['event_id'], 'mesmo _id não gera outra linha');
        $this->assertSame(1, $this->db->table('webhook_events')->countAllResults());
        $this->assertSame([], $this->transport->requests, 'nada é consultado no recebimento');

        $this->routeOrder($this->mlOrder('2000000001'));
        $stats = $this->notifications->processPending();

        $this->assertSame(1, $stats['processed']);
        $event = (new WebhookEventModel())->find($first['event_id']);
        $this->assertSame(WebhookEventModel::STATUS_PROCESSED, $event['status']);
        $this->assertStringContainsString('importado', $event['message']);
        $this->assertSame(1, $this->db->table('orders')->countAllResults());
    }

    public function testNotificationsForSameResourceAreCoalesced(): void
    {
        $this->notifications->receive($this->notification('/orders/2000000001', 'n-1'));
        $this->notifications->receive($this->notification('/orders/2000000001', 'n-2'));
        $this->routeOrder($this->mlOrder('2000000001'));

        $stats = $this->notifications->processPending();

        $this->assertSame(1, $stats['processed']);
        $this->assertCount(1, $this->transport->requestsTo('GET', '/orders/2000000001'));
        $this->assertSame(2, $this->db->table('webhook_events')->where('status', WebhookEventModel::STATUS_PROCESSED)->countAllResults());
    }

    public function testNotificationValidation(): void
    {
        $this->assertSame(400, $this->notifications->receive(['topic' => 'orders_v2'])['status']);
        $this->assertSame(403, $this->notifications->receive(['application_id' => 1] + $this->notification('/orders/1'))['status']);

        $other = $this->notifications->receive(['user_id' => 777] + $this->notification('/orders/2000000001', 'n-3'));
        $topic = $this->notifications->receive(['topic' => 'questions', 'resource' => '/questions/1'] + $this->notification('/x', 'n-4'));

        $this->assertSame(200, $other['status']);
        $events = new WebhookEventModel();
        $this->assertSame(WebhookEventModel::STATUS_IGNORED, $events->find($other['event_id'])['status']);
        $this->assertSame(WebhookEventModel::STATUS_IGNORED, $events->find($topic['event_id'])['status']);
        $this->assertSame(0, $this->notifications->processPending()['processed']);
    }

    public function testNotificationNotFoundFailsAndCanBeRetried(): void
    {
        $received = $this->notifications->receive($this->notification('/orders/2000000009'));
        $this->transport->on('GET', '/orders/2000000009', 404, ['message' => 'Order not found', 'error' => 'not_found', 'status' => 404]);

        $stats = $this->notifications->processPending();

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(WebhookEventModel::STATUS_FAILED, (new WebhookEventModel())->find($received['event_id'])['status']);

        $this->notifications->retry($received['event_id']);
        $this->assertSame(WebhookEventModel::STATUS_PENDING, (new WebhookEventModel())->find($received['event_id'])['status']);
    }

    public function testTemporaryFailureReturnsToQueue(): void
    {
        $received = $this->notifications->receive($this->notification('/orders/2000000001'));
        $this->transport->on('GET', '/orders/2000000001', 500, ['message' => 'internal error']);

        $stats = $this->notifications->processPending();

        $this->assertSame(1, $stats['retry']);
        $event = (new WebhookEventModel())->find($received['event_id']);
        $this->assertSame(WebhookEventModel::STATUS_PENDING, $event['status']);
        $this->assertSame('1', (string) $event['process_attempts']);
    }

    private function routeOrder(array $order): void
    {
        $this->transport->on('GET', '/orders/' . $order['id'], 200, $order);
    }

    /**
     * Order no formato da documentação "Gerenciamento de vendas".
     */
    private function mlOrder(string $id, array $overrides = []): array
    {
        $quantity = $overrides['quantity'] ?? 1;

        return [
            'id'           => (int) $id,
            'date_created' => '2026-10-02T09:30:00.000-03:00',
            'date_closed'  => '2026-10-02T09:31:00.000-03:00',
            'last_updated' => $overrides['last_updated'] ?? '2026-10-02T09:32:00.000-03:00',
            'status'       => $overrides['status'] ?? 'paid',
            'total_amount' => 200 * $quantity,
            'paid_amount'  => 200 * $quantity,
            'currency_id'  => 'BRL',
            'tags'         => $overrides['tags'] ?? ['paid'],
            'buyer'        => ['id' => 123],
            'seller'       => ['id' => $overrides['seller'] ?? 123456],
            'shipping'     => ['id' => 44000001],
            'payments'     => [['status' => 'approved', 'shipping_cost' => 0]],
            'order_items'  => [[
                'item'       => ['id' => 'MLB4000000001', 'title' => 'Fone Lunar X1', 'variation_id' => null, 'seller_sku' => $overrides['seller_sku'] ?? 'FONE-01'],
                'quantity'   => $quantity,
                'unit_price' => 200,
                'sale_fee'   => array_key_exists('sale_fee', $overrides) ? $overrides['sale_fee'] : 24,
            ]],
        ];
    }

    private function notification(string $resource, string $id = 'notif-1'): array
    {
        return [
            '_id'            => $id,
            'resource'       => $resource,
            'user_id'        => 123456,
            'topic'          => 'orders_v2',
            'application_id' => 5503910054141466,
            'attempts'       => 1,
            'sent'           => '2026-10-02T12:00:00.000Z',
            'received'       => '2026-10-02T12:00:00.000Z',
        ];
    }
}

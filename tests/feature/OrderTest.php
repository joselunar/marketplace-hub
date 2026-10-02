<?php

namespace Tests\Feature;

use App\DTO\MercadoLivre\TokenSet;
use App\Models\SupplierOrderModel;
use App\Models\WebhookEventModel;
use App\Services\Marketplaces\MarketplaceAccountService;
use App\Services\Suppliers\SupplierOfferService;
use CodeIgniter\Config\Factories;
use Config\MercadoLivre;
use Config\Services;
use Tests\Support\FakeMercadoLivreTransport;
use Tests\Support\FeatureTestCase;

final class OrderTest extends FeatureTestCase
{
    // O webhook não tem sessão nem token: precisa funcionar com o CSRF ligado.
    protected bool $disableCsrf = false;

    private FakeMercadoLivreTransport $transport;
    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        service('settings')->seedDefaults();

        $config                    = new MercadoLivre();
        $config->clientId          = '5503910054141466';
        $config->clientSecret      = 'segredo-do-app';
        $config->redirectUri       = 'http://localhost:8080/marketplaces/mercadolivre/callback';
        $config->webhookAllowedIps = [];
        Factories::injectMock('config', MercadoLivre::class, $config);

        $this->transport = new FakeMercadoLivreTransport();
        Services::injectMock('mercadoLivreTransport', $this->transport);
        Services::resetSingle('mercadoLivre');

        $tokens          = TokenSet::fromResponse(FakeMercadoLivreTransport::tokenResponse());
        $this->accountId = (new MarketplaceAccountService())->storeConnection('mercadolivre', $tokens, 'LOJA_TESTE', 'MLB', null)['id'];
    }

    public function testWebhookStoresNotificationWithoutSessionOrCsrf(): void
    {
        $result = $this->withBodyFormat('json')->post('webhooks/mercadolivre', $this->notification());

        $result->assertStatus(200);
        $this->seeInDatabase('webhook_events', ['topic' => 'orders_v2', 'resource' => '/orders/2000000001', 'status' => WebhookEventModel::STATUS_PENDING]);
        $this->assertSame([], $this->transport->requests, 'nenhuma chamada à API no recebimento');

        $this->withBodyFormat('json')->post('webhooks/mercadolivre', $this->notification())->assertStatus(200);
        $this->assertSame(1, $this->db->table('webhook_events')->countAllResults());
    }

    public function testWebhookRejectsInvalidRequests(): void
    {
        $this->withBodyFormat('json')->post('webhooks/mercadolivre', ['application_id' => 1] + $this->notification())->assertStatus(403);
        $this->withBodyFormat('json')->post('webhooks/mercadolivre', ['topic' => 'orders_v2'])->assertStatus(400);
        $this->withBody('não é json')->post('webhooks/mercadolivre')->assertStatus(400);

        $this->assertSame(0, $this->db->table('webhook_events')->countAllResults());
    }

    public function testWebhookHonorsIpAllowlist(): void
    {
        config(MercadoLivre::class)->webhookAllowedIps = ['54.88.218.97'];

        $this->withBodyFormat('json')->post('webhooks/mercadolivre', $this->notification())->assertStatus(403);
        $this->assertSame(0, $this->db->table('webhook_events')->countAllResults());
    }

    public function testOrderPagesAndSendToSupplier(): void
    {
        $user      = $this->createUser('operator');
        $productId = $this->createProduct(['sku' => 'FONE-01', 'name' => 'Fone Lunar X1']);
        $supplier  = $this->createSupplier(['name' => 'Distribuidora Alpha', 'default_shipping_days' => 2]);
        (new SupplierOfferService())->create(['supplier_id' => $supplier, 'product_id' => $productId, 'supplier_sku' => 'A-1', 'supplier_product_name' => 'Fone', 'cost_price' => '100', 'stock_quantity' => '10', 'shipping_cost' => '0', 'active' => '1']);

        $this->transport->on('GET', '/orders/2000000001', 200, $this->mlOrder());
        service('mercadoLivreNotifications')->receive($this->notification());
        $this->assertSame(1, service('mercadoLivreNotifications')->processPending()['processed']);

        $orderId = (int) $this->db->table('orders')->get()->getRow()->id;

        $index = $this->actingAs($user)->get('orders');
        $index->assertOK();
        $index->assertSee('2000000001');
        $index->assertSee('Aguardando envio ao fornecedor');

        $show = $this->actingAs($user)->get('orders/' . $orderId);
        $show->assertOK();
        $show->assertSee('Enviar pedido ao fornecedor');
        $show->assertSee('Distribuidora Alpha');
        $show->assertSee('R$ 64,00');

        $dashboard = $this->actingAs($user)->get('dashboard');
        $dashboard->assertSee('1 pedido(s) aguardando fornecedor');

        $supplierOrder = $this->db->table('supplier_orders')->get()->getRowArray();
        $sent          = $this->actingAs($user)->post('orders/supplier-orders/' . $supplierOrder['id'] . '/send', [
            csrf_token()                 => csrf_hash(),
            'order_id'                   => (string) $orderId,
            'external_supplier_order_id' => 'PED-77',
        ]);
        $sent->assertRedirectTo(site_url('orders/' . $orderId));
        $this->seeInDatabase('supplier_orders', ['id' => $supplierOrder['id'], 'status' => SupplierOrderModel::STATUS_SENT, 'external_supplier_order_id' => 'PED-77']);

        $api = $this->actingAs($user)->get('api/orders');
        $api->assertOK();
        $data = json_decode($api->getJSON(), true);
        $this->assertSame('2000000001', $data['data'][0]['external_order_id']);
        $this->assertSame('32.00', $data['data'][0]['margin']);

        $detail = json_decode($this->actingAs($user)->get('api/orders/' . $orderId)->getJSON(), true);
        $this->assertSame('sent', $detail['data']['supplier_orders'][0]['status']);
        $this->actingAs($user)->get('api/orders/999999')->assertStatus(404);
    }

    public function testNotificationsPageProcessesQueue(): void
    {
        $user = $this->createUser('operator');
        service('mercadoLivreNotifications')->receive($this->notification());
        $this->transport->on('GET', '/orders/2000000001', 404, ['message' => 'not found', 'status' => 404]);

        $page = $this->actingAs($user)->get('marketplaces/mercadolivre/notifications');
        $page->assertOK();
        $page->assertSee('/orders/2000000001');
        $page->assertSee('Processar fila (1)');

        $this->actingAs($user)->post('marketplaces/mercadolivre/notifications/process', [csrf_token() => csrf_hash()])
            ->assertRedirectTo(site_url('marketplaces/mercadolivre/notifications'));
        $this->seeInDatabase('webhook_events', ['resource' => '/orders/2000000001', 'status' => WebhookEventModel::STATUS_FAILED]);
    }

    private function notification(): array
    {
        return [
            '_id'            => 'notif-1',
            'resource'       => '/orders/2000000001',
            'user_id'        => 123456,
            'topic'          => 'orders_v2',
            'application_id' => 5503910054141466,
            'attempts'       => 1,
            'sent'           => '2026-10-02T12:00:00.000Z',
            'received'       => '2026-10-02T12:00:00.000Z',
        ];
    }

    private function mlOrder(): array
    {
        return [
            'id' => 2000000001, 'status' => 'paid', 'date_created' => '2026-10-02T09:30:00.000-03:00', 'last_updated' => '2026-10-02T09:32:00.000-03:00',
            'total_amount' => 200, 'paid_amount' => 200, 'currency_id' => 'BRL', 'tags' => ['paid'],
            'buyer' => ['id' => 123], 'seller' => ['id' => 123456], 'shipping' => ['id' => 44000001],
            'order_items' => [['item' => ['id' => 'MLB4000000001', 'title' => 'Fone Lunar X1', 'seller_sku' => 'FONE-01'], 'quantity' => 1, 'unit_price' => 200, 'sale_fee' => 24]],
        ];
    }
}

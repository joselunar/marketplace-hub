<?php

namespace Tests\Database\Marketplaces;

use App\Exceptions\BusinessRuleException;
use App\Jobs\ImportMarketplaceOrdersJob;
use App\Jobs\RefreshMarketplaceTokenJob;
use App\Models\JobModel;
use App\Models\SyncLogModel;
use App\Services\Marketplaces\Contracts\MarketplaceAdapter;
use App\Services\Marketplaces\Contracts\UnsupportedMarketplaceOperation;
use App\Services\Marketplaces\MarketplaceAccountService;
use App\Services\Marketplaces\MarketplaceRegistry;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreAdapter;
use Config\Marketplaces;
use LogicException;
use stdClass;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeMarketplaceAdapter;

/**
 * A automação e os pedidos passam pelo adaptador de cada marketplace, sem
 * mandar contas, anúncios ou notificações de um para o código de outro.
 */
final class MarketplaceIntegrationTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        service('settings')->seedDefaults();
    }

    public function testRegistryResolvesAdaptersAndRejectsUnknownMarketplaces(): void
    {
        $registry = service('marketplaces');

        $this->assertInstanceOf(MercadoLivreAdapter::class, $registry->get('mercadolivre'));
        $this->assertSame(['mercadolivre' => 'Mercado Livre'], $registry->names());
        foreach (array_keys(MarketplaceAdapter::CAPABILITY_LABELS) as $capability) {
            $this->assertTrue($registry->get('mercadolivre')->supports($capability), $capability);
        }

        $this->assertFalse($registry->has('shopee'));
        $this->expectException(BusinessRuleException::class);
        $registry->get('shopee');
    }

    public function testInvalidAdapterConfigurationIsRejected(): void
    {
        $config           = new Marketplaces();
        $config->adapters = ['outro' => stdClass::class];

        $this->expectException(LogicException::class);
        (new MarketplaceRegistry($config))->all();
    }

    public function testAccountsAndListingsResolveToTheirOwnAdapter(): void
    {
        FakeMarketplaceAdapter::register([]);
        $meli = $this->account('mercadolivre');
        $fake = $this->account(FakeMarketplaceAdapter::SLUG);

        $registry = service('marketplaces');
        $this->assertSame('mercadolivre', $registry->forAccount($meli)->slug());
        $this->assertSame(FakeMarketplaceAdapter::SLUG, $registry->forAccount($fake)->slug());
        $this->assertSame(FakeMarketplaceAdapter::SLUG, $registry->forListing($this->listing($fake))->slug());
    }

    public function testTokenTaskEnqueuesPerMarketplaceAndJobRefreshesThroughTheAdapter(): void
    {
        FakeMarketplaceAdapter::register([MarketplaceAdapter::CAPABILITY_TOKEN_REFRESH]);
        $account = $this->account(FakeMarketplaceAdapter::SLUG, ['token_expires_at' => date('Y-m-d H:i:s', time() + 1800)]);
        $this->account(FakeMarketplaceAdapter::SLUG, ['external_user_id' => '2', 'token_expires_at' => date('Y-m-d H:i:s', time() + 7200)]);

        $result = service('scheduler')->runNow('marketplace_tokens');

        $this->assertSame('1 job(s) enfileirado(s).', $result['message'], 'só o token dentro da antecedência do adaptador');
        $this->seeInDatabase('jobs', ['type' => RefreshMarketplaceTokenJob::TYPE, 'reference' => 'Marketplace de Teste: Loja ' . FakeMarketplaceAdapter::SLUG]);

        service('jobQueue')->work(5, 30);

        $this->assertSame([['refresh', $account]], FakeMarketplaceAdapter::$calls);
        $job = $this->db->table('jobs')->get()->getRowArray();
        $this->assertSame(JobModel::STATUS_COMPLETED, $job['status']);
        $this->assertStringStartsWith('Marketplace de Teste, conta Loja ' . FakeMarketplaceAdapter::SLUG . ': token válido até', (string) $job['result']);
    }

    public function testTokenTaskSkipsMarketplacesWithoutCredentials(): void
    {
        FakeMarketplaceAdapter::register([MarketplaceAdapter::CAPABILITY_TOKEN_REFRESH], false);
        $this->account(FakeMarketplaceAdapter::SLUG, ['token_expires_at' => date('Y-m-d H:i:s', time() + 60)]);

        service('scheduler')->runNow('marketplace_tokens');

        $this->assertSame(0, $this->db->table('jobs')->countAllResults());
    }

    public function testOrderImportOnlyReachesMarketplacesThatOfferIt(): void
    {
        FakeMarketplaceAdapter::register([]);
        $account = $this->account(FakeMarketplaceAdapter::SLUG);

        $this->assertSame('Nenhuma conta de marketplace conectada.', service('scheduler')->runNow('marketplace_orders')['message']);
        $this->assertSame(0, $this->db->table('jobs')->countAllResults());

        // Um job que chegue à fila mesmo assim falha de vez, sem novas tentativas.
        $queue = service('jobQueue');
        $queue->enqueue(ImportMarketplaceOrdersJob::TYPE, ['account_id' => $account]);
        $queue->work(5, 30);

        $job = $this->db->table('jobs')->get()->getRowArray();
        $this->assertSame(JobModel::STATUS_FAILED, $job['status']);
        $this->assertSame('1', (string) $job['attempts']);
        $this->assertStringContainsString('não oferece "Importação de pedidos"', (string) $job['last_error']);
        $this->seeInDatabase('sync_logs', ['integration_type' => 'automation', 'status' => SyncLogModel::STATUS_ERROR]);
        $this->assertFalse($queue->isRetryable(UnsupportedMarketplaceOperation::for(service('marketplaces')->get(FakeMarketplaceAdapter::SLUG), MarketplaceAdapter::CAPABILITY_ORDERS)));
    }

    public function testOrderTaskRunsTheImportOfEachMarketplace(): void
    {
        FakeMarketplaceAdapter::register([MarketplaceAdapter::CAPABILITY_ORDERS]);
        $account = $this->account(FakeMarketplaceAdapter::SLUG);

        service('scheduler')->runNow('marketplace_orders');
        service('jobQueue')->work(5, 30);

        $this->assertSame([['orders', $account]], FakeMarketplaceAdapter::$calls);
        $this->seeInDatabase('jobs', ['status' => JobModel::STATUS_COMPLETED, 'result' => 'Marketplace de Teste: 0 novo(s), 0 atualizado(s), 0 sem mudança, 0 com erro.']);
    }

    public function testListingUpdatesSkipMarketplacesWithoutTheCapability(): void
    {
        FakeMarketplaceAdapter::register([]);
        $meliListing = $this->listing($this->account('mercadolivre'), 'MLB1');
        $this->listing($this->account(FakeMarketplaceAdapter::SLUG), 'T-1');

        $autoSync = service('listingAutoSync');

        $this->assertSame([['id' => $meliListing, 'external_listing_id' => 'MLB1']], $autoSync->stockCandidates());
        $this->assertSame([], $autoSync->stockCandidates(null, FakeMarketplaceAdapter::SLUG));
    }

    public function testMercadoLivreIgnoresNotificationsFromOtherMarketplaces(): void
    {
        $this->db->table('webhook_events')->insert([
            'provider'    => FakeMarketplaceAdapter::SLUG,
            'dedupe_key'  => sha1('outro'),
            'topic'       => 'orders',
            'resource'    => '/orders/1',
            'user_id'     => '1',
            'status'      => 'pending',
            'payload'     => '{}',
            'received_at' => date('Y-m-d H:i:s'),
        ]);

        $notifications = service('mercadoLivreNotifications');

        $this->assertSame(0, $notifications->pendingCount());
        $this->assertSame(['processed' => 0, 'failed' => 0, 'retry' => 0], $notifications->processPending());
        $this->seeInDatabase('webhook_events', ['provider' => FakeMarketplaceAdapter::SLUG, 'status' => 'pending']);
        $this->assertSame('Nenhuma notificação na fila.', service('scheduler')->runNow('marketplace_notifications', null, JobModel::SOURCE_SCHEDULE)['message']);
    }

    private function account(string $slug, array $overrides = []): int
    {
        $marketplace = (new MarketplaceAccountService())->marketplace($slug);

        $this->db->table('marketplace_accounts')->insert($overrides + [
            'marketplace_id'   => (int) $marketplace['id'],
            'name'             => 'Loja ' . $slug,
            'external_user_id' => '1',
            'status'           => 'connected',
            'active'           => 1,
            'token_expires_at' => date('Y-m-d H:i:s', time() + 21600),
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    private function listing(int $accountId, string $externalId = 'X-1'): int
    {
        $this->db->table('marketplace_listings')->insert([
            'marketplace_account_id' => $accountId,
            'product_id'             => $this->createProduct(),
            'external_listing_id'    => $externalId,
            'selling_model'          => 'classic',
            'title'                  => 'Produto anunciado',
            'category_id'            => 'CAT1',
            'listing_type'           => 'gold_special',
            'currency_id'            => 'BRL',
            'price'                  => '100.00',
            'available_quantity'     => 5,
            'status'                 => 'active',
            'created_at'             => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }
}

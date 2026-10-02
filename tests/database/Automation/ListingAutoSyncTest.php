<?php

namespace Tests\Database\Automation;

use App\DTO\MercadoLivre\TokenSet;
use App\Jobs\SyncMercadoLivrePricesJob;
use App\Jobs\SyncMercadoLivreStockJob;
use App\Models\JobModel;
use App\Services\Automation\JobQueue;
use App\Services\Marketplaces\MarketplaceAccountService;
use App\Services\Suppliers\SupplierOfferService;
use App\Services\Suppliers\SupplierSelectionService;
use CodeIgniter\Config\Factories;
use Config\MercadoLivre;
use Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeMercadoLivreTransport;

/**
 * Custo 100, estoque 10 e segurança 2: estoque esperado no anúncio 8 e
 * preço 175,90 com a comissão oficial de 12%.
 */
final class ListingAutoSyncTest extends DatabaseTestCase
{
    private FakeMercadoLivreTransport $transport;
    private int $productId;
    private int $offerId;
    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        service('settings')->seedDefaults();

        $config               = new MercadoLivre();
        $config->clientId     = 'APP123';
        $config->clientSecret = 'segredo-do-app';
        $config->redirectUri  = 'http://localhost:8080/marketplaces/mercadolivre/callback';
        Factories::injectMock('config', MercadoLivre::class, $config);
        $this->transport = new FakeMercadoLivreTransport();
        Services::injectMock('mercadoLivreTransport', $this->transport);
        Services::resetSingle('mercadoLivre');

        $tokens          = TokenSet::fromResponse(FakeMercadoLivreTransport::tokenResponse());
        $this->accountId = (int) (new MarketplaceAccountService())->storeConnection('mercadolivre', $tokens, 'LOJA_TESTE', 'MLB', null)['id'];

        $this->productId = $this->createProduct(['sku' => 'FONE-01', 'name' => 'Fone Bluetooth Lunar X1']);
        $supplier        = $this->createSupplier(['name' => 'Distribuidora Alpha', 'default_shipping_days' => 2]);
        $this->offerId   = (new SupplierOfferService())->create([
            'supplier_id' => $supplier, 'product_id' => $this->productId, 'supplier_sku' => 'A-1', 'supplier_product_name' => 'Fone',
            'cost_price' => '100', 'stock_quantity' => '10', 'shipping_cost' => '0', 'active' => '1',
        ]);
    }

    public function testCandidatesAreListingsOutOfSyncWithTheSupplier(): void
    {
        $listing = $this->listing(['available_quantity' => 5]);
        $this->listing(['external_listing_id' => 'MLB2', 'available_quantity' => 0, 'product_id' => $this->createProduct()]);
        $this->listing(['external_listing_id' => 'MLB3', 'available_quantity' => 1, 'status' => 'closed', 'product_id' => $this->createProduct()]);

        $autoSync = service('listingAutoSync');

        $this->assertSame([['id' => $listing, 'external_listing_id' => 'MLB1']], $autoSync->stockCandidates(), 'sem fornecedor o esperado é zero; finalizado fica de fora');
        $this->assertSame([['id' => $listing, 'external_listing_id' => 'MLB1']], $autoSync->priceCandidates(), 'só produtos com preço válido');

        $this->db->table('marketplace_accounts')->update(['status' => 'reauthorize']);
        $this->assertSame([], $autoSync->stockCandidates(), 'conta sem autorização não recebe atualização');
    }

    public function testSelectionChangesQueueUpdatesOnlyWhenEnabled(): void
    {
        $this->listing(['available_quantity' => 8]);

        $this->changeStock(4);
        $this->assertSame(0, $this->db->table('jobs')->countAllResults(), 'desligado por padrão');

        $this->enable('sync.auto_update_stock');
        $this->changeStock(5);

        $job = $this->db->table('jobs')->get()->getRowArray();
        $this->assertSame(SyncMercadoLivreStockJob::TYPE, $job['type']);
        $this->assertSame(JobModel::SOURCE_EVENT, $job['source']);
        $this->assertSame('MLB1', $job['reference']);
        $this->assertSame(0, $this->db->table('jobs')->where('type', SyncMercadoLivrePricesJob::TYPE)->countAllResults());

        $this->enable('sync.auto_update_price');
        $this->db->table('supplier_offers')->where('id', $this->offerId)->update(['cost_price' => '120.00']);
        service('supplierSelection')->select($this->productId, SupplierSelectionService::TRIGGER_OFFER);
        $this->assertSame(1, $this->db->table('jobs')->where('type', SyncMercadoLivrePricesJob::TYPE)->countAllResults(), 'custo mudou o preço sugerido');

        service('supplierSelection')->select($this->productId, SupplierSelectionService::TRIGGER_OFFER);
        $this->assertSame(1, $this->db->table('jobs')->where('type', SyncMercadoLivrePricesJob::TYPE)->countAllResults(), 'recálculo sem mudança de preço não enfileira');
    }

    public function testStockJobSendsQuantityAndSkipsWhenAlreadyCorrect(): void
    {
        $listingId = $this->listing(['available_quantity' => 5]);
        $this->transport->on('PUT', '/items/MLB1', 200, ['id' => 'MLB1', 'available_quantity' => 8, 'status' => 'active']);
        $queue = new JobQueue();

        $queue->enqueue(SyncMercadoLivreStockJob::TYPE, ['listing_id' => $listingId]);
        $queue->work(10, 10);

        $this->assertSame(['available_quantity' => 8], FakeMercadoLivreTransport::json($this->transport->requestsTo('PUT', '/items/MLB1')[0]));
        $this->seeInDatabase('marketplace_listings', ['id' => $listingId, 'available_quantity' => 8]);
        $this->seeInDatabase('jobs', ['status' => JobModel::STATUS_COMPLETED, 'result' => 'Estoque enviado: 5 → 8.']);

        $queue->enqueue(SyncMercadoLivreStockJob::TYPE, ['listing_id' => $listingId]);
        $queue->work(10, 10);
        $this->assertCount(1, $this->transport->requestsTo('PUT', '/items/MLB1'), 'quantidade igual não chama a API');
        $this->seeInDatabase('jobs', ['status' => JobModel::STATUS_COMPLETED, 'result' => 'Estoque já estava correto (8).']);
    }

    public function testMarketplaceOutageIsRetried(): void
    {
        $listingId = $this->listing(['available_quantity' => 5]);
        $this->transport->on('PUT', '/items/MLB1', 503, ['message' => 'Service unavailable', 'error' => 'service_unavailable', 'status' => 503]);
        $queue = new JobQueue();

        $queue->enqueue(SyncMercadoLivreStockJob::TYPE, ['listing_id' => $listingId]);
        $stats = $queue->work(10, 10);

        $this->assertSame(1, $stats['retried']);
        $job = $this->db->table('jobs')->get()->getRowArray();
        $this->assertSame(JobModel::STATUS_PENDING, $job['status']);
        $this->assertStringContainsString('instável', $job['last_error']);
        $this->seeInDatabase('marketplace_listings', ['id' => $listingId, 'available_quantity' => 5]);
    }

    public function testPriceJobUsesOfficialFeeAndRefusesNothingWhenPriceIsRight(): void
    {
        $listingId = $this->listing(['price' => '150.00']);
        $this->transport->on('GET', '/sites/MLB/listing_prices', 200, [[
            'currency_id' => 'BRL', 'listing_type_id' => 'gold_special', 'listing_type_name' => 'Clássico', 'listing_fee_amount' => 0,
            'listing_fee_details' => ['fixed_fee' => 0, 'gross_amount' => 0], 'requires_picture' => true, 'sale_fee_amount' => 21.11,
            'sale_fee_details' => ['financing_add_on_fee' => 0, 'fixed_fee' => 0, 'gross_amount' => 21.11, 'meli_percentage_fee' => 0, 'percentage_fee' => 12],
        ]]);
        $this->transport->on('PUT', '/items/MLB1', 200, ['id' => 'MLB1', 'price' => 175.9, 'status' => 'active']);
        $queue = new JobQueue();

        $queue->enqueue(SyncMercadoLivrePricesJob::TYPE, ['listing_id' => $listingId]);
        $queue->work(10, 10);

        $this->assertSame(['price' => 175.9], FakeMercadoLivreTransport::json($this->transport->requestsTo('PUT', '/items/MLB1')[0]));
        $this->seeInDatabase('jobs', ['status' => JobModel::STATUS_COMPLETED, 'result' => 'Preço enviado: R$ 150,00 → R$ 175,90.']);

        $queue->enqueue(SyncMercadoLivrePricesJob::TYPE, ['listing_id' => $listingId]);
        $queue->work(10, 10);
        $this->assertCount(1, $this->transport->requestsTo('PUT', '/items/MLB1'));
        $this->assertSame(1, $this->db->table('marketplace_listing_events')->countAllResults(), 'conferência sem mudança não gera evento');
    }

    private function changeStock(int $quantity): void
    {
        $this->db->table('supplier_offers')->where('id', $this->offerId)->update(['stock_quantity' => $quantity]);
        service('supplierSelection')->select($this->productId, SupplierSelectionService::TRIGGER_OFFER);
    }

    private function enable(string $key): void
    {
        $this->db->table('settings')->where('key', $key)->update(['value' => '1']);
        Services::resetSingle('settings');
        Services::resetSingle('listingAutoSync');
    }

    private function listing(array $overrides = []): int
    {
        $this->db->table('marketplace_listings')->insert($overrides + [
            'marketplace_account_id' => $this->accountId,
            'product_id'             => $this->productId,
            'external_listing_id'    => 'MLB1',
            'selling_model'          => 'classic',
            'title'                  => 'Fone Bluetooth Lunar X1',
            'category_id'            => 'MLB196208',
            'listing_type'           => 'gold_special',
            'currency_id'            => 'BRL',
            'price'                  => '175.90',
            'available_quantity'     => 8,
            'status'                 => 'active',
            'sale_fee_percentage'    => '12.00',
            'sale_fixed_fee'         => '0.00',
            'created_at'             => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }
}

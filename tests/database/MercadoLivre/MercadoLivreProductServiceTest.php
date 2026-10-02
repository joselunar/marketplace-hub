<?php

namespace Tests\Database\MercadoLivre;

use App\DTO\MercadoLivre\TokenSet;
use App\Exceptions\BusinessRuleException;
use App\Models\MarketplaceListingModel;
use App\Services\Marketplaces\MarketplaceAccountService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreApiClient;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreAuthService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreProductService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreService;
use App\Services\Suppliers\SupplierOfferService;
use Config\MercadoLivre;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeMercadoLivreTransport;

/**
 * Configuração padrão: margem desejada 25%, mínima 10%, imposto 6%,
 * arredondamento ",90" e estoque de segurança 2. Com custo 100 e a
 * comissão oficial de 12%: 100 / (1 − 0,43) = 175,44 → 175,90.
 */
final class MercadoLivreProductServiceTest extends DatabaseTestCase
{
    private FakeMercadoLivreTransport $transport;
    private MercadoLivreService $meli;
    private MercadoLivreProductService $service;
    private array $account;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        service('settings')->seedDefaults();

        $config               = new MercadoLivre();
        $config->clientId     = 'APP123';
        $config->clientSecret = 'segredo-do-app';
        $config->redirectUri  = 'http://localhost:8080/marketplaces/mercadolivre/callback';
        $config->logisticType = 'drop_off';
        $config->shippingMode = 'me2';

        $this->transport = new FakeMercadoLivreTransport();
        $api             = new MercadoLivreApiClient($config, $this->transport);
        $accounts        = new MarketplaceAccountService();
        $this->meli      = new MercadoLivreService($config, $api, new MercadoLivreAuthService($config, $api, $accounts), $accounts);
        $this->service   = new MercadoLivreProductService($this->meli);

        $tokens        = TokenSet::fromResponse(FakeMercadoLivreTransport::tokenResponse());
        $connection    = $accounts->storeConnection('mercadolivre', $tokens, 'LOJA_TESTE', 'MLB', null);
        $this->account = $this->meli->resolveAccount($connection['id']);

        $this->productId = $this->createProduct([
            'sku' => 'FONE-01', 'name' => 'Fone Bluetooth Lunar X1', 'brand' => 'Lunar', 'ean' => '7891234567895',
            'description' => "Fone sem fio.\nBateria de 20 horas.",
        ]);
        $this->db->table('product_images')->insert(['product_id' => $this->productId, 'url' => 'https://cdn.exemplo.com/fone.jpg', 'position' => 0, 'created_at' => date('Y-m-d H:i:s')]);

        $supplier = $this->createSupplier(['name' => 'Distribuidora Alpha', 'default_shipping_days' => 2]);
        (new SupplierOfferService())->create([
            'supplier_id' => $supplier, 'product_id' => $this->productId, 'supplier_sku' => 'A-1', 'supplier_product_name' => 'Fone',
            'cost_price' => '100', 'stock_quantity' => '10', 'shipping_cost' => '0', 'active' => '1',
        ]);

        $this->routeCategory();
    }

    public function testClassicPreviewUsesOfficialFeeSafeStockAndTitle(): void
    {
        $this->routeSeller(['normal']);

        $preview = $this->service->previews()->preview($this->productId, ['category_id' => 'MLB196208']);

        $this->assertSame([], $preview->errors);
        $this->assertTrue($preview->canPublish());
        $this->assertSame(MarketplaceListingModel::MODEL_CLASSIC, $preview->sellingModel);
        $this->assertSame(8, $preview->quantity, '10 em estoque − 2 de segurança');
        $this->assertSame('175.90', $preview->price());
        $this->assertSame('12.00', $preview->fee->percentageFee);
        $this->assertSame('gold_special', $preview->listingTypeId, 'Clássico é o padrão quando disponível');

        $payload = $preview->payload;
        $this->assertSame('Fone Bluetooth Lunar X1', $payload['title']);
        $this->assertArrayNotHasKey('family_name', $payload);
        $this->assertSame(175.9, $payload['price']);
        $this->assertSame('BRL', $payload['currency_id']);
        $this->assertSame('buy_it_now', $payload['buying_mode']);
        $this->assertSame([['source' => 'https://cdn.exemplo.com/fone.jpg']], $payload['pictures']);
        $this->assertContains(['id' => 'BRAND', 'value_name' => 'Lunar'], $payload['attributes']);
        $this->assertContains(['id' => 'GTIN', 'value_name' => '7891234567895'], $payload['attributes']);

        $fees = $this->transport->requestsTo('GET', '/sites/MLB/listing_prices');
        $this->assertNotEmpty($fees);
        parse_str((string) parse_url($fees[0]['url'], PHP_URL_QUERY), $query);
        $this->assertSame(['price' => '145.90', 'category_id' => 'MLB196208', 'listing_type_id' => 'gold_special', 'currency_id' => 'BRL', 'logistic_type' => 'drop_off', 'shipping_mode' => 'me2'], $query);
        $this->assertSame([], $this->transport->requestsTo('POST', '/items'), 'a prévia não envia nada');
    }

    public function testUserProductsSellerSendsFamilyNameWithoutTitle(): void
    {
        $this->routeSeller(['normal', 'user_product_seller']);

        $preview = $this->service->previews()->preview($this->productId, ['category_id' => 'MLB196208', 'family_name' => 'Fone Lunar X1']);

        $this->assertSame(MarketplaceListingModel::MODEL_USER_PRODUCTS, $preview->sellingModel);
        $this->assertSame('Fone Lunar X1', $preview->payload['family_name']);
        $this->assertArrayNotHasKey('title', $preview->payload);
        $this->assertArrayNotHasKey('variations', $preview->payload);
    }

    public function testPreviewBlocksMissingRequiredAttributeAndForbiddenWord(): void
    {
        $this->routeSeller(['normal']);

        $preview = $this->service->previews()->preview($this->productId, [
            'category_id' => 'MLB196208',
            'title'       => 'Fone Lunar pronta entrega estoque',
            'attributes'  => ['BRAND' => '', 'GTIN' => '7891234567895'],
        ]);

        $this->assertFalse($preview->canPublish());
        $this->assertContains('Preencha o atributo obrigatório "Marca".', $preview->errors);
        $this->assertContains('O título não pode conter a palavra "estoque" (o Mercado Livre modera o anúncio).', $preview->errors);
    }

    public function testPublishSendsItemAndDescriptionAndStoresIds(): void
    {
        $this->routeSeller(['normal', 'user_product_seller']);
        $this->transport->on('POST', '/items', 201, [
            'id' => 'MLB4000000001', 'user_product_id' => 'MLBU9000001', 'title' => 'Fone Bluetooth Lunar X1 Preto', 'family_name' => 'Fone Lunar X1',
            'price' => 175.9, 'available_quantity' => 8, 'status' => 'active', 'sub_status' => [],
            'permalink' => 'https://produto.mercadolivre.com.br/MLB-4000000001', 'tags' => ['user_product_listing'],
        ]);
        $this->transport->on('POST', '/items/MLB4000000001/description', 201, ['plain_text' => 'ok']);
        $this->db->table('categories')->insert(['name' => 'Áudio', 'slug' => 'audio', 'active' => 1]);
        $categoryId = (int) $this->db->insertID();
        $this->db->table('products')->where('id', $this->productId)->update(['category_id' => $categoryId]);

        $listing = $this->service->publish($this->productId, ['category_id' => 'MLB196208', 'family_name' => 'Fone Lunar X1'], '175.90', 8, null, null);

        $this->assertSame('MLB4000000001', $listing['external_listing_id']);
        $this->assertSame('MLBU9000001', $listing['external_product_id']);
        $this->assertSame(MarketplaceListingModel::STATUS_ACTIVE, $listing['status']);
        $this->assertSame('Fone Bluetooth Lunar X1 Preto', $listing['title'], 'título gerado pelo Mercado Livre');
        $this->assertSame('175.90', $listing['price']);
        $this->assertSame('12.00', $listing['sale_fee_percentage']);
        $this->assertSame([], $listing['warnings']);

        $sent = FakeMercadoLivreTransport::json($this->transport->requestsTo('POST', '/items')[0]);
        $this->assertSame('Fone Lunar X1', $sent['family_name']);
        $this->assertSame(8, $sent['available_quantity']);

        $description = FakeMercadoLivreTransport::json($this->transport->requestsTo('POST', '/items/MLB4000000001/description')[0]);
        $this->assertSame("Fone sem fio.\nBateria de 20 horas.", $description['plain_text']);

        $event = $this->db->table('marketplace_listing_events')->get()->getRowArray();
        $this->assertSame(['publish', 'success'], [$event['action'], $event['status']]);
        $this->assertSame(1, $this->db->table('audit_logs')->where('action', 'publish')->countAllResults());

        $mapping = $this->db->table('marketplace_category_mappings')->get()->getRowArray();
        $this->assertSame(['MLB196208', (string) $categoryId], [$mapping['external_category_id'], (string) $mapping['category_id']], 'próximas publicações da categoria já sugerem a mesma categoria do ML');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('já tem o anúncio MLB4000000001');
        $this->service->publish($this->productId, ['category_id' => 'MLB196208'], '175.90', 8, null, null);
    }

    public function testPublishRefusesWhenPriceChangedSincePreview(): void
    {
        $this->routeSeller(['normal']);

        try {
            $this->service->publish($this->productId, ['category_id' => 'MLB196208'], '150.00', 8, null, null);
            $this->fail('Deveria recusar preço diferente do recalculado.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('mudaram desde a prévia', $e->getMessage());
        }

        $this->assertSame([], $this->transport->requestsTo('POST', '/items'));
        $this->assertSame(0, $this->db->table('marketplace_listings')->countAllResults());
    }

    public function testRejectedPublicationIsRecordedAndCanBeRetried(): void
    {
        $this->routeSeller(['normal']);
        $this->transport->on('POST', '/items', 400, [
            'message' => 'Validation error', 'error' => 'validation_error', 'status' => 400,
            'cause'   => [['code' => 'item.attribute.missing_required', 'message' => 'The attribute MODEL is required', 'type' => 'error']],
        ]);

        try {
            $this->service->publish($this->productId, ['category_id' => 'MLB196208'], '175.90', 8, null, null);
            $this->fail('Deveria repassar a recusa do Mercado Livre.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('The attribute MODEL is required', $e->getMessage());
        }

        $listing = $this->db->table('marketplace_listings')->get()->getRowArray();
        $this->assertSame(MarketplaceListingModel::STATUS_ERROR, $listing['status']);
        $this->assertNull($listing['external_listing_id']);
        $this->assertSame(1, $this->db->table('sync_logs')->where('action', 'listing_publish')->where('status', 'error')->countAllResults());

        $this->transport->routesReset('POST', '/items')->on('POST', '/items', 201, ['id' => 'MLB4000000002', 'title' => 'Fone Bluetooth Lunar X1', 'price' => 175.9, 'available_quantity' => 8, 'status' => 'active']);
        $this->transport->on('POST', '/items/MLB4000000002/description', 201, []);

        $retried = $this->service->publish($this->productId, ['category_id' => 'MLB196208'], '175.90', 8, null, null);

        $this->assertSame((int) $listing['id'], (int) $retried['id'], 'a mesma linha é reaproveitada');
        $this->assertSame('MLB4000000002', $retried['external_listing_id']);
        $this->assertSame(1, $this->db->table('marketplace_listings')->countAllResults());
    }

    public function testFeeOscillationKeepsHighestPrice(): void
    {
        $this->routeSeller(['normal']);
        $this->transport->routesReset('GET', '/sites/MLB/listing_prices');
        // Abaixo da faixa há tarifa fixa; com ela o preço passa da faixa e a tarifa some.
        $this->transport->on('GET', '/sites/MLB/listing_prices', 200, [$this->fee('6.75')])
            ->on('GET', '/sites/MLB/listing_prices', 200, [$this->fee('0')])
            ->on('GET', '/sites/MLB/listing_prices', 200, [$this->fee('6.75')]);

        $preview = $this->service->previews()->preview($this->productId, ['category_id' => 'MLB196208']);

        // (100 + 6,75) / 0,57 = 187,28 → 187,90; sem a tarifa fixa: 175,90.
        $this->assertSame('187.90', $preview->price());
        $this->assertSame('0.00', $preview->breakdown->marketplaceFixedFee);
        $this->assertFalse($preview->breakdown->isBelowMinimum());
    }

    public function testUpdatePriceStockPauseAndActivate(): void
    {
        $listingId = $this->publishedListing(['price' => '150.00', 'available_quantity' => 3]);

        $this->transport->on('PUT', '/items/MLB4000000009', 200, ['id' => 'MLB4000000009', 'price' => 175.9, 'status' => 'active']);
        $listing = $this->service->updatePrice($listingId);
        $this->assertSame('175.90', $listing['price']);
        $this->assertSame(['price' => 175.9], FakeMercadoLivreTransport::json($this->transport->requestsTo('PUT', '/items/MLB4000000009')[0]));

        $this->transport->routesReset('PUT', '/items/MLB4000000009');
        $this->transport->on('PUT', '/items/MLB4000000009', 200, ['id' => 'MLB4000000009', 'available_quantity' => 8, 'status' => 'active']);
        $listing = $this->service->updateStock($listingId);
        $this->assertSame(8, (int) $listing['available_quantity']);
        $this->assertSame(['available_quantity' => 8], FakeMercadoLivreTransport::json($this->transport->requestsTo('PUT', '/items/MLB4000000009')[1]));

        $this->transport->routesReset('PUT', '/items/MLB4000000009');
        $this->transport->on('PUT', '/items/MLB4000000009', 200, ['id' => 'MLB4000000009', 'status' => 'paused', 'sub_status' => []]);
        $this->assertSame('paused', $this->service->pause($listingId)['status']);
        $this->assertSame(['status' => 'paused'], FakeMercadoLivreTransport::json($this->transport->requestsTo('PUT', '/items/MLB4000000009')[2]));

        try {
            $this->service->pause($listingId);
            $this->fail('Não deveria pausar um anúncio já pausado.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Só é possível pausar', $e->getMessage());
        }

        $this->transport->routesReset('PUT', '/items/MLB4000000009');
        $this->transport->on('PUT', '/items/MLB4000000009', 200, ['id' => 'MLB4000000009', 'status' => 'active', 'sub_status' => []]);
        $this->assertSame('active', $this->service->activate($listingId)['status']);

        $actions = array_column($this->db->table('marketplace_listing_events')->orderBy('id')->get()->getResultArray(), 'action');
        $this->assertSame(['update_price', 'update_stock', 'pause', 'activate'], $actions);
    }

    public function testSyncReadsItemAndRecordsChanges(): void
    {
        $listingId = $this->publishedListing();
        $this->transport->on('GET', '/items/MLB4000000009', 200, [
            'id' => 'MLB4000000009', 'title' => 'Fone Bluetooth Lunar X1', 'price' => 180, 'available_quantity' => 0,
            'status' => 'paused', 'sub_status' => ['out_of_stock'], 'permalink' => 'https://produto.mercadolivre.com.br/MLB-4000000009',
        ]);

        $listing = $this->service->sync($listingId);

        $this->assertSame('paused', $listing['status']);
        $this->assertSame('out_of_stock', $listing['sub_status']);
        $this->assertSame('180.00', $listing['price']);
        $this->assertNotNull($listing['last_sync_at']);
        $this->assertSame('Dados atualizados a partir do Mercado Livre.', $this->db->table('marketplace_listing_events')->get()->getRowArray()['message']);
    }

    public function testApiErrorOnUpdateIsStoredInHistory(): void
    {
        $listingId = $this->publishedListing(['available_quantity' => 3]);
        $this->transport->on('PUT', '/items/MLB4000000009', 400, ['message' => 'Item is under review', 'error' => 'item.status.invalid', 'status' => 400, 'cause' => []]);

        try {
            $this->service->updateStock($listingId);
            $this->fail('Deveria repassar o erro.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Item is under review', $e->getMessage());
        }

        $listing = $this->db->table('marketplace_listings')->where('id', $listingId)->get()->getRowArray();
        $this->assertSame(3, (int) $listing['available_quantity'], 'estoque local não muda se a API recusar');
        $this->assertStringContainsString('Item is under review', $listing['last_error']);
        $this->assertSame('error', $this->db->table('marketplace_listing_events')->get()->getRowArray()['status']);
    }

    public function testAutomaticPublishIsDisabledByDefault(): void
    {
        $this->assertFalse($this->service->automaticPublishEnabled());
    }

    private function publishedListing(array $overrides = []): int
    {
        $this->db->table('marketplace_listings')->insert($overrides + [
            'marketplace_account_id' => $this->account['id'],
            'product_id'             => $this->productId,
            'external_listing_id'    => 'MLB4000000009',
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

    private function routeSeller(array $tags): void
    {
        $this->transport->on('GET', '/users/123456', 200, ['id' => 123456, 'nickname' => 'LOJA_TESTE', 'site_id' => 'MLB', 'tags' => $tags]);
    }

    private function routeCategory(): void
    {
        $this->transport->on('GET', '/categories/MLB196208', 200, [
            'id'                  => 'MLB196208',
            'name'                => 'Fones de Ouvido',
            'path_from_root'      => [['id' => 'MLB1000', 'name' => 'Eletrônicos, Áudio e Vídeo'], ['id' => 'MLB196208', 'name' => 'Fones de Ouvido']],
            'children_categories' => [],
            'settings'            => ['listing_allowed' => true, 'max_title_length' => 60, 'item_conditions' => ['new', 'used'], 'currencies' => ['BRL'], 'max_pictures_per_item' => 12],
        ]);
        $this->transport->on('GET', '/categories/MLB196208/attributes', 200, [
            ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true]],
            ['id' => 'GTIN', 'name' => 'Código universal de produto', 'value_type' => 'string', 'tags' => ['conditional_required' => true]],
            ['id' => 'COLOR', 'name' => 'Cor', 'value_type' => 'list', 'tags' => []],
        ]);
        $this->transport->on('GET', '/users/123456/available_listing_types', 200, [
            'category_id' => 'MLB196208',
            'available'   => [
                ['site_id' => 'MLB', 'id' => 'gold_pro', 'name' => 'Premium', 'remaining_listings' => null],
                ['site_id' => 'MLB', 'id' => 'gold_special', 'name' => 'Clássico', 'remaining_listings' => null],
            ],
        ]);
        $this->transport->on('GET', '/sites/MLB/listing_prices', 200, [[$this->fee('0')]]);
    }

    private function fee(string $fixed): array
    {
        return [
            'currency_id'         => 'BRL',
            'listing_type_id'     => 'gold_special',
            'listing_type_name'   => 'Clássico',
            'listing_fee_amount'  => 0,
            'listing_fee_details' => ['fixed_fee' => 0, 'gross_amount' => 0],
            'requires_picture'    => true,
            'sale_fee_amount'     => 21.11,
            'sale_fee_details'    => ['financing_add_on_fee' => 0, 'fixed_fee' => (float) $fixed, 'gross_amount' => 21.11, 'meli_percentage_fee' => 0, 'percentage_fee' => 12],
        ];
    }
}

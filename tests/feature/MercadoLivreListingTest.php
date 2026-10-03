<?php

namespace Tests\Feature;

use App\DTO\MercadoLivre\TokenSet;
use App\Services\Marketplaces\MarketplaceAccountService;
use App\Services\Suppliers\SupplierOfferService;
use CodeIgniter\Config\Factories;
use Config\MercadoLivre;
use Config\Services;
use Tests\Support\FakeMercadoLivreTransport;
use Tests\Support\FeatureTestCase;

final class MercadoLivreListingTest extends FeatureTestCase
{
    private FakeMercadoLivreTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        service('settings')->seedDefaults();

        $config               = new MercadoLivre();
        $config->clientId     = 'APP123';
        $config->clientSecret = 'segredo-do-app';
        $config->redirectUri  = site_url('marketplaces/mercadolivre/callback');
        Factories::injectMock('config', MercadoLivre::class, $config);

        $this->transport = new FakeMercadoLivreTransport();
        Services::injectMock('mercadoLivreTransport', $this->transport);
        Services::resetSingle('mercadoLivre');
    }

    public function testPreviewWithoutAccountExplainsWhatToDo(): void
    {
        $productId = $this->createProduct(['name' => 'Fone Bluetooth Lunar X1']);

        $page = $this->actingAs($this->createUser('operator'))->get('marketplaces/mercadolivre/listings/new?product_id=' . $productId);

        $page->assertOK();
        $page->assertSee('Nenhuma conta do Mercado Livre conectada');
        $page->assertSee('Conectar uma conta do Mercado Livre');
    }

    public function testPreviewThenConfirmPublishes(): void
    {
        $productId = $this->productWithOffer();
        $this->storeAccount();
        $this->routeApi();
        $user = $this->createUser('operator');

        $preview = $this->actingAs($user)->get('marketplaces/mercadolivre/listings/new?product_id=' . $productId . '&category_id=MLB196208');
        $preview->assertOK();
        $preview->assertSee('R$ 175,90');
        $preview->assertSee('Confirmar e publicar');
        $preview->assertSee('"title": "Fone Bluetooth Lunar X1"');
        $this->assertSame([], $this->transport->requestsTo('POST', '/items'));

        $result = $this->actingAs($user)->post('marketplaces/mercadolivre/listings', [
            'product_id'       => (string) $productId,
            'category_id'      => 'MLB196208',
            'listing_type_id'  => 'gold_special',
            'condition'        => 'new',
            'title'            => 'Fone Bluetooth Lunar X1',
            'attributes'       => ['BRAND' => 'Lunar'],
            'confirm_price'    => '175.90',
            'confirm_quantity' => '8',
        ]);

        $listing = $this->db->table('marketplace_listings')->get()->getRowArray();
        $result->assertRedirectTo(site_url('marketplaces/mercadolivre/listings/' . $listing['id']));
        $result->assertSessionHas('success', 'Anúncio MLB4000000001 publicado no Mercado Livre.');
        $this->assertSame('MLB4000000001', $listing['external_listing_id']);
        $this->assertSame(['ACTIVE', '138.90', '175.90'], [$listing['price_automation_status'], $listing['price_automation_min'], $listing['price_automation_max']], 'automação de preço ligada por padrão');

        $index = $this->actingAs($user)->get('marketplaces/mercadolivre/listings');
        $index->assertOK();
        $index->assertSee('MLB4000000001');
        $index->assertSee('Distribuidora Alpha');
        $index->assertSee('Publicação automática');

        $show = $this->actingAs($user)->get('marketplaces/mercadolivre/listings/' . $listing['id']);
        $show->assertOK();
        $show->assertSee('Anúncio MLB4000000001 criado');
        $show->assertSee('Pausar');
    }

    public function testConfirmWithStaleValuesGoesBackToPreview(): void
    {
        $productId = $this->productWithOffer();
        $this->storeAccount();
        $this->routeApi();

        $result = $this->actingAs($this->createUser('admin'))->post('marketplaces/mercadolivre/listings', [
            'product_id'       => (string) $productId,
            'category_id'      => 'MLB196208',
            'title'            => 'Fone Bluetooth Lunar X1',
            'confirm_price'    => '99.90',
            'confirm_quantity' => '8',
        ]);

        $result->assertRedirect();
        $this->assertStringContainsString('listings/new?product_id=' . $productId, $result->response()->getHeaderLine('Location'));
        $this->assertStringContainsString('mudaram desde a prévia', (string) session('error'));
        $this->assertSame([], $this->transport->requestsTo('POST', '/items'));
    }

    public function testListingsPageAndProductButton(): void
    {
        $productId = $this->createProduct(['name' => 'Produto Vitrine']);
        $user      = $this->createUser('operator');

        $empty = $this->actingAs($user)->get('marketplaces/mercadolivre/listings');
        $empty->assertOK();
        $empty->assertSee('Nenhum anúncio publicado ainda');

        $product = $this->actingAs($user)->get('products/' . $productId);
        $product->assertSee('Publicar no Mercado Livre');
        $product->assertSee('marketplaces/mercadolivre/listings/new?product_id=' . $productId);
    }

    private function productWithOffer(): int
    {
        $productId = $this->createProduct(['sku' => 'FONE-01', 'name' => 'Fone Bluetooth Lunar X1', 'brand' => 'Lunar']);
        $this->db->table('product_images')->insert(['product_id' => $productId, 'url' => 'https://cdn.exemplo.com/fone.jpg', 'position' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        $supplier = $this->createSupplier(['name' => 'Distribuidora Alpha', 'default_shipping_days' => 2]);
        (new SupplierOfferService())->create([
            'supplier_id' => $supplier, 'product_id' => $productId, 'supplier_sku' => 'A-1', 'supplier_product_name' => 'Fone',
            'cost_price' => '100', 'stock_quantity' => '10', 'shipping_cost' => '0', 'active' => '1',
        ]);

        return $productId;
    }

    private function storeAccount(): int
    {
        $tokens = TokenSet::fromResponse(FakeMercadoLivreTransport::tokenResponse());

        return (new MarketplaceAccountService())->storeConnection('mercadolivre', $tokens, 'LOJA_TESTE', 'MLB', null)['id'];
    }

    private function routeApi(): void
    {
        $this->transport
            ->on('GET', '/users/123456', 200, ['id' => 123456, 'tags' => ['normal']])
            ->on('GET', '/categories/MLB196208', 200, [
                'id' => 'MLB196208', 'name' => 'Fones de Ouvido', 'path_from_root' => [['id' => 'MLB196208', 'name' => 'Fones de Ouvido']],
                'children_categories' => [], 'settings' => ['listing_allowed' => true, 'max_title_length' => 60, 'item_conditions' => ['new'], 'currencies' => ['BRL']],
            ])
            ->on('GET', '/categories/MLB196208/attributes', 200, [['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true]]])
            ->on('GET', '/users/123456/available_listing_types', 200, ['available' => [['id' => 'gold_special', 'name' => 'Clássico', 'remaining_listings' => null]]])
            ->on('GET', '/sites/MLB/listing_prices', 200, [[
                'listing_type_id' => 'gold_special', 'listing_type_name' => 'Clássico', 'currency_id' => 'BRL', 'listing_fee_amount' => 0,
                'requires_picture' => true, 'sale_fee_amount' => 21.11, 'sale_fee_details' => ['fixed_fee' => 0, 'percentage_fee' => 12],
            ]])
            ->on('POST', '/items', 201, ['id' => 'MLB4000000001', 'title' => 'Fone Bluetooth Lunar X1', 'price' => 175.9, 'available_quantity' => 8, 'status' => 'active', 'permalink' => 'https://produto.mercadolivre.com.br/MLB-4000000001'])
            ->on('GET', '/pricing-automation/items/MLB4000000001/rules', 200, ['item_id' => 'MLB4000000001', 'rules' => [['rule_id' => 'INT']]])
            ->on('POST', '/pricing-automation/items/MLB4000000001/automation', 200, ['item_id' => 'MLB4000000001', 'status' => 'ACTIVE', 'item_rule' => ['rule_id' => 'INT'], 'min_price' => 138.9, 'max_price' => 175.9]);
    }
}

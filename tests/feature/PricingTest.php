<?php

namespace Tests\Feature;

use App\Database\Seeds\DevelopmentSeeder;
use Tests\Support\FeatureTestCase;

final class PricingTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DevelopmentSeeder::class);
    }

    public function testProductPageShowsPriceBreakdown(): void
    {
        $result = $this->actingAs($this->createUser('operator'))->get('products/' . $this->productId('SMW-FIT-PRO'));

        $result->assertOK();
        $result->assertSee('Precificação');
        $result->assertSee('Preço sugerido');
        $result->assertSee('Comissão do marketplace');
        $result->assertSee('Lucro estimado');
        $result->assertSee('configuração global');
        $result->assertSee('Preço / margem');
    }

    public function testFixedPriceBelowMinimumIsHighlighted(): void
    {
        $user = $this->actingAs($this->createUser('operator'));

        $page = $user->get('products/' . $this->productId('CARR-USBC-20W'));
        $page->assertSee('não atinge a margem mínima com nenhum fornecedor');
        $page->assertSee('Preço fixo');

        $list = $user->get('products?pricing=below_minimum');
        $list->assertSee('CARR-USBC-20W');
        $list->assertDontSee('SMW-FIT-PRO');
        $list->assertSee('Margem baixa');

        $dashboard = $user->get('dashboard');
        $dashboard->assertSee('com margem abaixo da mínima no preço fixo');
    }

    public function testFixedPriceChoosesSupplierThatKeepsMinimumMargin(): void
    {
        $result = $this->actingAs($this->createUser('operator'))->get('products/' . $this->productId('FONE-LP40'));

        $result->assertSee('categoria Áudio');
        $result->assertSee('Inelegível: Margem de');
        $this->seeInDatabase('products', ['sku' => 'FONE-LP40', 'price_status' => 'ok', 'sale_price' => '95.90']);
    }

    public function testRejectsFixedPriceBelowMinimumOnSave(): void
    {
        $productId = $this->productId('SMW-FIT-PRO');
        $product   = $this->db->table('products')->where('id', $productId)->get()->getRowArray();

        $result = $this->actingAs($this->createUser('operator'))->post("products/{$productId}", [
            'sku' => $product['sku'], 'name' => $product['name'], 'category_id' => $product['category_id'], 'active' => '1', 'sale_price' => '120,00',
        ]);

        $result->assertRedirect();
        $this->assertStringContainsString('abaixo da mínima', ((array) session('errors'))['sale_price'] ?? '');
        $this->seeInDatabase('products', ['id' => $productId, 'sale_price' => null]);
    }

    public function testSimulatorPageAndFragment(): void
    {
        $user = $this->actingAs($this->createUser('operator'));

        $page = $user->get('pricing?cost_price=100&shipping_cost=10');
        $page->assertOK();
        $page->assertSee('Simulador de preços');
        $page->assertSee('Preço sugerido');
        $page->assertSee('Total de custos');

        $invalid = $user->get('pricing/simulate?cost_price=abc');
        $invalid->assertStatus(422);
        $invalid->assertSee('Não foi possível calcular');
    }

    public function testApiSimulationAndProductPricing(): void
    {
        $user = $this->actingAs($this->createUser('operator'));

        $simulation = $user->get('api/pricing/simulate?cost_price=100&sale_price=150');
        $simulation->assertOK();
        $data = json_decode($simulation->getJSON(), true)['data'];
        $this->assertSame('manual', $data['mode']);
        $this->assertSame('150.00', $data['price']);
        $this->assertArrayHasKey('minimum_price', $data);

        $invalid = $user->get('api/pricing/simulate');
        $invalid->assertStatus(400);

        $product = $user->get('api/products/' . $this->productId('SMW-FIT-PRO') . '/pricing');
        $product->assertOK();
        $payload = json_decode($product->getJSON(), true)['data'];
        $this->assertSame('ok', $payload['price_status']);
        $this->assertSame('Fornecedor Gamma', $payload['supplier_name']);
        $this->assertSame('suggested', $payload['pricing']['mode']);

        $user->get('api/products/999999/pricing')->assertStatus(404);
    }

    public function testSavingPricingSettingsRepricesProducts(): void
    {
        $before   = $this->db->table('products')->where('sku', 'SMW-FIT-PRO')->get()->getRow()->suggested_price;
        $settings = service('settings')->all();
        $settings['pricing.marketplace_fee_percentage'] = '18';
        $settings = array_map(static fn ($value) => is_bool($value) ? ($value ? '1' : '0') : (string) $value, $settings);

        $result = $this->actingAs($this->createUser('admin'))->post('settings', ['settings' => $settings]);

        $result->assertRedirectTo(site_url('settings'));
        $after = $this->db->table('products')->where('sku', 'SMW-FIT-PRO')->get()->getRow()->suggested_price;
        $this->assertGreaterThan((float) $before, (float) $after);
    }

    private function productId(string $sku): int
    {
        return (int) $this->db->table('products')->where('sku', $sku)->get()->getRow()->id;
    }
}

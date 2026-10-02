<?php

namespace Tests\Feature;

use Tests\Support\FeatureTestCase;

final class MarketplaceTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        service('settings')->seedDefaults();
    }

    public function testOperatorSeesMarketplacesButCannotChangeFees(): void
    {
        $operator = $this->createUser('operator');
        $page     = $this->actingAs($operator)->get('marketplaces');

        $page->assertOK();
        $page->assertSee('Mercado Livre');
        $page->assertSee('Importação de pedidos');
        $page->assertSee('Um administrador altera nesta tela.');
        $page->assertDontSee('Salvar tarifas');

        $this->actingAs($operator)->post('marketplaces/mercadolivre/fees', ['fee_percentage' => '10'])->assertStatus(403);
        $this->assertNull($this->db->table('marketplaces')->where('slug', 'mercadolivre')->get()->getRowArray()['fee_percentage']);
    }

    public function testAdminSavesFeesAndInvalidValuesAreRejected(): void
    {
        $admin = $this->createUser('admin');
        $this->actingAs($admin)->get('marketplaces')->assertSee('Salvar tarifas');

        $saved = $this->actingAs($admin)->post('marketplaces/mercadolivre/fees', ['fee_percentage' => '12,5', 'fixed_fee' => '', 'fixed_fee_threshold' => '']);
        $saved->assertRedirectTo(site_url('marketplaces'));
        $saved->assertSessionHas('success');
        $this->seeInDatabase('marketplaces', ['slug' => 'mercadolivre', 'fee_percentage' => '12.50', 'fixed_fee' => null]);

        $rejected = $this->actingAs($admin)->post('marketplaces/mercadolivre/fees', ['fee_percentage' => '150']);
        $rejected->assertRedirectTo(site_url('marketplaces'));
        $rejected->assertSessionHas('errors');
        $this->seeInDatabase('marketplaces', ['slug' => 'mercadolivre', 'fee_percentage' => '12.50']);

        $this->actingAs($admin)->post('marketplaces/shopee/fees', ['fee_percentage' => '10'])->assertSessionHas('error');
    }

    public function testApiListsMarketplacesWithoutCredentials(): void
    {
        $response = $this->actingAs($this->createUser('operator'))->get('api/marketplaces');

        $response->assertOK();
        $data = json_decode($response->getJSON(), true)['data'];
        $this->assertSame('mercadolivre', $data[0]['slug']);
        $this->assertContains('orders', $data[0]['capabilities']);
        $this->assertArrayHasKey('fee_percentage', $data[0]['fees']);
        $this->assertStringNotContainsString('_encrypted', $response->getJSON());

        $invalid = $this->actingAs($this->createUser('operator'))->get('api/pricing/simulate?cost_price=100&marketplace=shopee');
        $invalid->assertStatus(400);
        $this->assertStringContainsString('Marketplace inválido.', json_decode($invalid->getJSON(), true)['messages']['marketplace'] ?? '');
    }

    public function testSimulatorOffersMarketplaceFees(): void
    {
        $page = $this->actingAs($this->createUser('operator'))->get('pricing?cost_price=100&marketplace=mercadolivre');

        $page->assertOK();
        $page->assertSee('Tarifas do marketplace');
        $this->assertStringContainsString('<option value="mercadolivre" selected>Mercado Livre</option>', $page->getBody());
    }
}

<?php

namespace Tests\Feature;

use App\Database\Seeds\DevelopmentSeeder;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\FeatureTestCase;

final class SupplierSelectionTest extends FeatureTestCase
{
    use StreamFilterTrait;

    public function testProductPageExplainsTheChoice(): void
    {
        $this->seed(DevelopmentSeeder::class);
        $productId = $this->productId('SMW-FIT-PRO');

        $result = $this->actingAs($this->createUser('operator'))->get("products/{$productId}");

        $result->assertOK();
        $result->assertSee('Fornecedor selecionado');
        $result->assertSee('Motivos');
        $result->assertSee('Comparação');
        $result->assertSee('Reserva #1');
        $result->assertSee('Inelegível: Estoque de segurança: 1 informado, 3 reservado');
        $result->assertSee('Histórico de troca de fornecedor');
        $result->assertDontSee('A seleção gravada está desatualizada');
    }

    public function testProductWithoutEligibleSupplierShowsWarning(): void
    {
        $this->seed(DevelopmentSeeder::class);

        $result = $this->actingAs($this->createUser('operator'))->get('products/' . $this->productId('CABO-HDMI-2M'));

        $result->assertOK();
        $result->assertSee('Nenhum fornecedor elegível');
        $result->assertSee('Sem estoque no fornecedor');
    }

    public function testRecalculateButtonPersistsSelection(): void
    {
        $this->seed(DevelopmentSeeder::class);
        $productId = $this->productId('FONE-LP40');
        $user      = $this->createUser('operator');
        $this->db->table('products')->where('id', $productId)->update(['selected_offer_id' => null]);

        $result = $this->actingAs($user)->post("products/{$productId}/selection");

        $result->assertRedirectTo(site_url("products/{$productId}"));
        $result->assertSessionHas('success', 'Seleção recalculada: Fornecedor Gamma é o fornecedor principal.');
        $this->seeInDatabase('supplier_selection_logs', ['product_id' => $productId, 'trigger' => 'manual', 'user_id' => $user['id']]);
    }

    public function testProductListShowsSelectedSupplierAndFilter(): void
    {
        $this->seed(DevelopmentSeeder::class);
        $user = $this->createUser('operator');

        $list = $this->actingAs($user)->get('products?sku=SMW');
        $list->assertSee('Fornecedor Gamma');

        $none = $this->actingAs($user)->get('products?selection=none');
        $none->assertSee('CABO-HDMI-2M');
        $none->assertDontSee('SMW-FIT-PRO');
    }

    public function testSavingRankingWeightsRecalculatesSelection(): void
    {
        $this->seed(DevelopmentSeeder::class);
        $settings = service('settings')->all();
        $settings['ranking.weight_stock'] = '40';

        $result = $this->actingAs($this->createUser('admin'))->post('settings', ['settings' => $this->formValues($settings)]);

        $result->assertRedirectTo(site_url('settings'));
        $this->assertStringContainsString('Seleção de fornecedores recalculada para 7 produto(s)', (string) session('success'));
    }

    public function testRejectsAllRankingWeightsZero(): void
    {
        $this->seed(DevelopmentSeeder::class);
        $settings = service('settings')->all();

        foreach (['price', 'stock', 'shipping', 'reliability', 'priority'] as $criterion) {
            $settings['ranking.weight_' . $criterion] = '0';
        }

        $result = $this->actingAs($this->createUser('admin'))->post('settings', ['settings' => $this->formValues($settings)]);

        $result->assertRedirect();
        $this->assertArrayHasKey('ranking.weight_price', (array) session('errors'));
    }

    public function testCommandRecalculatesAllProducts(): void
    {
        $this->seed(DevelopmentSeeder::class);

        $this->db->table('products')->update(['selected_offer_id' => null]);

        command('suppliers:select');
        $this->assertStringContainsString('7 produto(s) avaliado(s), 6 troca(s)', $this->getStreamFilterBuffer());

        // 7 produtos com ofertas; o cabo HDMI não tem nenhuma elegível.
        $this->assertSame(6, $this->db->table('products')->where('selected_offer_id IS NOT NULL', null, false)->countAllResults());
        $this->assertSame(6, $this->db->table('supplier_selection_logs')->where('trigger', 'command')->countAllResults());
    }

    private function productId(string $sku): int
    {
        return (int) $this->db->table('products')->where('sku', $sku)->get()->getRow()->id;
    }

    /**
     * Converte os valores tipados em strings, como chegariam do formulário.
     */
    private function formValues(array $settings): array
    {
        return array_map(static fn ($value) => is_bool($value) ? ($value ? '1' : '0') : (string) $value, $settings);
    }
}

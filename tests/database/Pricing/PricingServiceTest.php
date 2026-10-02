<?php

namespace Tests\Database\Pricing;

use App\DTO\Pricing\MarginPolicy;
use App\Exceptions\ValidationException;
use App\Services\Catalog\CategoryService;
use App\Services\Catalog\ProductService;
use App\Services\Suppliers\SupplierOfferService;
use Tests\Support\DatabaseTestCase;

/**
 * Configuração padrão nos testes: margem desejada 25%, mínima 10%, imposto 6%,
 * sem tarifas do marketplace e arredondamento para ",90".
 */
final class PricingServiceTest extends DatabaseTestCase
{
    private int $productId;
    private int $alpha;
    private int $gamma;

    protected function setUp(): void
    {
        parent::setUp();

        service('settings')->seedDefaults();

        $this->productId = $this->createProduct(['sku' => 'PROD-P', 'name' => 'Produto P']);
        $this->alpha     = $this->createSupplier(['name' => 'Alpha', 'priority' => 7, 'default_shipping_days' => 2]);
        $this->gamma     = $this->createSupplier(['name' => 'Gamma', 'priority' => 6, 'default_shipping_days' => 3]);
    }

    public function testMarginsFollowProductCategoryParentGlobal(): void
    {
        $this->db->table('categories')->insert(['name' => 'Pai', 'slug' => 'pai', 'desired_margin_percentage' => '20.00', 'active' => 1]);
        $parent = (int) $this->db->insertID();
        $this->db->table('categories')->insert(['name' => 'Filha', 'slug' => 'filha', 'parent_id' => $parent, 'minimum_margin_percentage' => '15.00', 'active' => 1]);
        $child = (int) $this->db->insertID();

        $pricing = service('pricing');

        $global = $pricing->marginPolicy([]);
        $this->assertSame(['25.00', MarginPolicy::SOURCE_GLOBAL, '10.00', MarginPolicy::SOURCE_GLOBAL], [$global->desired, $global->desiredSource, $global->minimum, $global->minimumSource]);

        $inherited = $pricing->marginPolicy(['category_id' => $child]);
        $this->assertSame('20.00', $inherited->desired);
        $this->assertSame('categoria Pai', $inherited->desiredSourceLabel());
        $this->assertSame('15.00', $inherited->minimum);
        $this->assertSame('categoria Filha', $inherited->minimumSourceLabel());

        $own = $pricing->marginPolicy(['category_id' => $child, 'desired_margin_percentage' => '40']);
        $this->assertSame(['40.00', 'definida no produto'], [$own->desired, $own->desiredSourceLabel()]);
        $this->assertSame('15.00', $own->minimum);
    }

    public function testSelectionStoresPriceOfPrimarySupplier(): void
    {
        $this->offer($this->alpha, ['cost_price' => '100']);

        // 100 / (1 − 0,31) = 144,93 → 145,90; mínimo 100 / 0,84 = 119,05.
        $this->seeInDatabase('products', [
            'id'               => $this->productId,
            'suggested_price'  => '145.90',
            'minimum_price'    => '119.05',
            'estimated_profit' => '37.15',
            'estimated_margin' => '25.46',
            'price_status'     => 'ok',
        ]);
    }

    public function testManualPriceBelowMinimumIsRejected(): void
    {
        $this->offer($this->alpha, ['cost_price' => '100']);

        try {
            $this->updateProduct(['sale_price' => '110,00']);
            $this->fail('Preço abaixo da margem mínima deveria ser recusado.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'Com o preço de R$ 110,00 a margem fica em 3,1%, abaixo da mínima de 10,0%. Preço mínimo: R$ 119,05 (com Alpha).',
                $e->getErrors()['sale_price'],
            );
        }

        $this->seeInDatabase('products', ['id' => $this->productId, 'sale_price' => null]);
    }

    public function testManualPriceSwitchesToSupplierThatKeepsTheMargin(): void
    {
        $alphaOffer = $this->offer($this->alpha, ['cost_price' => '100', 'stock_quantity' => '20']);
        $gammaOffer = $this->offer($this->gamma, ['cost_price' => '105', 'stock_quantity' => '150']);
        $this->seeInDatabase('products', ['id' => $this->productId, 'selected_offer_id' => $gammaOffer]);

        // A 120: Alpha fica com 10,67% de margem; Gamma, com 6,5%.
        $this->updateProduct(['sale_price' => '120']);

        $this->seeInDatabase('products', ['id' => $this->productId, 'selected_offer_id' => $alphaOffer, 'sale_price' => '120.00', 'price_status' => 'ok', 'estimated_margin' => '10.67']);

        $log = $this->lastLog();
        $this->assertSame('product', $log['trigger']);
        $this->assertStringContainsString('Troca automática para Alpha', $log['reason']);
        $this->assertStringContainsString('Gamma ficou inelegível (Margem de 6,5% no preço de R$ 120,00, abaixo da mínima de 10,0%', $log['reason']);
    }

    public function testCostIncreaseLeavesManualPriceBelowMinimum(): void
    {
        $offerId = $this->offer($this->alpha, ['cost_price' => '100']);
        $this->updateProduct(['sale_price' => '150']);

        (new SupplierOfferService())->update($offerId, $this->offerInput($this->alpha, ['cost_price' => '140']));

        $this->seeInDatabase('products', [
            'id'                => $this->productId,
            'selected_offer_id' => null,
            'price_status'      => 'below_minimum',
            'estimated_margin'  => '0.67',
            'minimum_price'     => '166.67',
        ]);
        $this->assertStringContainsString('Sem fornecedor elegível: Alpha ficou inelegível (Margem de 0,7%', $this->lastLog()['reason']);
    }

    public function testCategoryMarginChangeRepricesItsProducts(): void
    {
        $categoryId = (new CategoryService())->create(['name' => 'Ferramentas', 'active' => '1']);
        $this->db->table('products')->where('id', $this->productId)->update(['category_id' => $categoryId]);
        $this->offer($this->alpha, ['cost_price' => '100']);
        $this->seeInDatabase('products', ['id' => $this->productId, 'suggested_price' => '145.90']);

        $stats = (new CategoryService())->update($categoryId, ['name' => 'Ferramentas', 'active' => '1', 'desired_margin_percentage' => '40']);

        $this->assertSame(['evaluated' => 1, 'changed' => 0], $stats);
        // 100 / (1 − 0,46) = 185,19 → 185,90
        $this->seeInDatabase('products', ['id' => $this->productId, 'suggested_price' => '185.90']);
    }

    public function testCategoryMinimumCannotExceedDesired(): void
    {
        $this->expectException(ValidationException::class);

        (new CategoryService())->create(['name' => 'Inválida', 'desired_margin_percentage' => '10', 'minimum_margin_percentage' => '20']);
    }

    public function testSimulationValidatesInput(): void
    {
        try {
            service('pricing')->simulate(['cost_price' => '', 'sale_price' => 'abc', 'desired_margin_percentage' => '120']);
            $this->fail('Era esperada uma ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['cost_price', 'desired_margin_percentage', 'sale_price'], array_keys($e->getErrors()));
        }
    }

    public function testSimulationUsesCategoryMargins(): void
    {
        $categoryId = (new CategoryService())->create(['name' => 'Premium', 'active' => '1', 'desired_margin_percentage' => '40', 'minimum_margin_percentage' => '20']);

        $breakdown = service('pricing')->simulate(['cost_price' => '100', 'category_id' => (string) $categoryId]);

        $this->assertSame('185.90', $breakdown->price);
        $this->assertSame('categoria Premium', $breakdown->margins->desiredSourceLabel());
        $this->assertSame('135.14', $breakdown->minimumPrice, '100 / (1 − 0,26)');
    }

    private function updateProduct(array $changes): void
    {
        $product = (new ProductService())->findOrFail($this->productId);

        (new ProductService())->update($this->productId, $changes + [
            'sku' => $product['sku'], 'name' => $product['name'], 'active' => '1', 'category_id' => $product['category_id'],
        ]);
    }

    private function offer(int $supplierId, array $overrides = []): int
    {
        return (new SupplierOfferService())->create($this->offerInput($supplierId, $overrides));
    }

    private function offerInput(int $supplierId, array $overrides = []): array
    {
        return $overrides + [
            'supplier_id'           => $supplierId,
            'product_id'            => $this->productId,
            'supplier_sku'          => 'SKU-' . $supplierId,
            'supplier_product_name' => 'Produto P',
            'cost_price'            => '100',
            'stock_quantity'        => '10',
            'shipping_cost'         => '0',
            'active'                => '1',
        ];
    }

    private function lastLog(): array
    {
        return $this->db->table('supplier_selection_logs')->orderBy('id', 'DESC')->get()->getRowArray();
    }
}

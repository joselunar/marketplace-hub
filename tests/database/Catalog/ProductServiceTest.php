<?php

namespace Tests\Database\Catalog;

use App\Exceptions\ValidationException;
use App\Services\Catalog\ProductService;
use Tests\Support\DatabaseTestCase;

final class ProductServiceTest extends DatabaseTestCase
{
    private function validInput(array $overrides = []): array
    {
        return $overrides + [
            'sku'    => 'fone-01',
            'ean'    => '789.1234.567895',
            'name'   => 'Fone Bluetooth Ação',
            'brand'  => 'Lenovo',
            'weight' => '0,150',
            'height' => '10,5',
            'active' => '1',
        ];
    }

    public function testCreatesProductNormalizingInputAndImages(): void
    {
        $id = (new ProductService())->create($this->validInput(), ['https://cdn.exemplo.com/a.jpg', '', 'https://cdn.exemplo.com/b.jpg']);

        $this->seeInDatabase('products', [
            'id'     => $id,
            'sku'    => 'FONE-01',
            'ean'    => '7891234567895',
            'slug'   => 'fone-bluetooth-acao',
            'weight' => '0.150',
            'height' => '10.50',
        ]);
        $this->assertSame(2, $this->db->table('product_images')->where('product_id', $id)->countAllResults());
        $this->seeInDatabase('audit_logs', ['entity_type' => 'product', 'entity_id' => $id, 'action' => 'create']);
    }

    public function testGeneratesUniqueSlugForSameName(): void
    {
        $service = new ProductService();
        $service->create($this->validInput(['sku' => 'A-1', 'ean' => '']));
        $second = $service->create($this->validInput(['sku' => 'A-2', 'ean' => '']));

        $this->seeInDatabase('products', ['id' => $second, 'slug' => 'fone-bluetooth-acao-2']);
    }

    public function testRejectsDuplicateSkuAndInvalidData(): void
    {
        $service = new ProductService();
        $service->create($this->validInput());

        try {
            $service->create($this->validInput(['ean' => '', 'weight' => 'pesado']));
            $this->fail('Era esperada uma ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sku', $e->getErrors());
            $this->assertArrayHasKey('weight', $e->getErrors());
        }
    }

    public function testRejectsNonHttpImageUrls(): void
    {
        $this->expectException(ValidationException::class);

        (new ProductService())->create($this->validInput(), ['javascript:alert(1)']);
    }

    public function testUpdateKeepsSlugAndReplacesImages(): void
    {
        $service = new ProductService();
        $id      = $service->create($this->validInput(), ['https://cdn.exemplo.com/a.jpg']);

        $service->update($id, $this->validInput(['brand' => 'Outra']), ['https://cdn.exemplo.com/c.jpg']);

        $this->seeInDatabase('products', ['id' => $id, 'brand' => 'Outra', 'slug' => 'fone-bluetooth-acao']);
        $this->seeInDatabase('product_images', ['product_id' => $id, 'url' => 'https://cdn.exemplo.com/c.jpg']);
        $this->dontSeeInDatabase('product_images', ['product_id' => $id, 'url' => 'https://cdn.exemplo.com/a.jpg']);
    }

    public function testDeletingProductUnlinksOffers(): void
    {
        $supplierId = $this->createSupplier();
        $id         = (new ProductService())->create($this->validInput());
        $this->db->table('supplier_offers')->insert([
            'supplier_id' => $supplierId, 'product_id' => $id, 'supplier_sku' => 'X', 'supplier_product_name' => 'X',
            'cost_price'  => '10.00', 'stock_quantity' => 1,
        ]);

        (new ProductService())->delete($id);

        $this->dontSeeInDatabase('products', ['id' => $id]);
        $this->seeInDatabase('supplier_offers', ['supplier_sku' => 'X', 'product_id' => null]);
    }
}

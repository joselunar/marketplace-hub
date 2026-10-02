<?php

namespace Tests\Database\Suppliers;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Services\Suppliers\SupplierOfferService;
use App\Services\Suppliers\SupplierService;
use Tests\Support\DatabaseTestCase;

final class SupplierServicesTest extends DatabaseTestCase
{
    public function testCreatesSupplierNormalizingDocument(): void
    {
        $id = (new SupplierService())->create([
            'name'             => 'Distribuidora XPTO',
            'document'         => '12.345.678/0001-90',
            'email'            => 'Contato@XPTO.com',
            'integration_type' => 'csv',
            'status'           => 'active',
            'priority'         => '8',
        ]);

        $this->seeInDatabase('suppliers', ['id' => $id, 'document' => '12345678000190', 'email' => 'contato@xpto.com']);
    }

    public function testRejectsUnknownIntegrationType(): void
    {
        $this->expectException(ValidationException::class);

        (new SupplierService())->create(['name' => 'X', 'integration_type' => 'scraping', 'status' => 'active']);
    }

    public function testDoesNotDeleteSupplierWithOffers(): void
    {
        $supplierId = $this->createSupplier();
        (new SupplierOfferService())->create([
            'supplier_id' => $supplierId, 'supplier_sku' => 'A', 'supplier_product_name' => 'A',
            'cost_price'  => '10', 'stock_quantity' => '1', 'active' => '1',
        ]);

        $this->expectException(BusinessRuleException::class);
        (new SupplierService())->delete($supplierId);
    }

    public function testOfferSkuIsUniquePerSupplier(): void
    {
        $alpha   = $this->createSupplier();
        $beta    = $this->createSupplier();
        $service = new SupplierOfferService();
        $input   = ['supplier_sku' => 'SKU-1', 'supplier_product_name' => 'Item', 'cost_price' => '49,90', 'stock_quantity' => '3', 'active' => '1'];

        $service->create($input + ['supplier_id' => $alpha]);
        // Mesmo SKU em outro fornecedor é permitido.
        $service->create($input + ['supplier_id' => $beta]);

        $this->seeInDatabase('supplier_offers', ['supplier_id' => $alpha, 'supplier_sku' => 'SKU-1', 'cost_price' => '49.90']);

        try {
            $service->create($input + ['supplier_id' => $alpha]);
            $this->fail('Era esperada uma ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('supplier_sku', $e->getErrors());
        }
    }

    public function testOfferRequiresPositiveCost(): void
    {
        $this->expectException(ValidationException::class);

        (new SupplierOfferService())->create([
            'supplier_id' => $this->createSupplier(), 'supplier_sku' => 'Z', 'supplier_product_name' => 'Z',
            'cost_price'  => '0', 'stock_quantity' => '1',
        ]);
    }

    public function testLinksAndUnlinksOfferToProduct(): void
    {
        $service   = new SupplierOfferService();
        $productId = $this->createProduct();
        $offerId   = $service->create([
            'supplier_id' => $this->createSupplier(), 'supplier_sku' => 'L', 'supplier_product_name' => 'L',
            'cost_price'  => '5', 'stock_quantity' => '0',
        ]);

        $service->link($offerId, $productId);
        $this->seeInDatabase('supplier_offers', ['id' => $offerId, 'product_id' => $productId]);

        $service->link($offerId, null);
        $this->seeInDatabase('supplier_offers', ['id' => $offerId, 'product_id' => null]);
    }
}

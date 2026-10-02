<?php

namespace Tests\Database\Suppliers;

use App\Exceptions\BusinessRuleException;
use App\Models\SupplierImportModel;
use App\Services\Suppliers\SupplierImportService;
use Tests\Support\DatabaseTestCase;

final class SupplierImportServiceTest extends DatabaseTestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'import');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    public function testCreatesUpdatesAndLinksOffers(): void
    {
        $supplierId = $this->createSupplier(['integration_type' => 'csv']);
        $bySku      = $this->createProduct(['sku' => 'INT-001']);
        $byEan      = $this->createProduct(['sku' => 'INT-002', 'ean' => '7891234567895']);

        // Oferta já existente: deve ser atualizada, não duplicada.
        $this->db->table('supplier_offers')->insert([
            'supplier_id' => $supplierId, 'supplier_sku' => 'EXIST-1', 'supplier_product_name' => 'Antigo',
            'cost_price'  => '10.00', 'stock_quantity' => 1, 'active' => 1,
        ]);

        file_put_contents($this->file, implode("\n", [
            'supplier_sku;name;cost_price;stock_quantity;ean;product_sku',
            'NEW-SKU;Por SKU interno;20,00;5;;int-001',
            'NEW-EAN;Por EAN;30,00;6;7891234567895;',
            'NEW-NONE;Sem correspondência;40,00;7;;',
            'EXIST-1;Atualizado;11,50;9;;',
            'BAD;Custo inválido;abc;1;;',
        ]));

        $importId = (new SupplierImportService())->importCsv($supplierId, $this->file, 'feed.csv', null);

        $import = (new SupplierImportModel())->find($importId);
        $this->assertSame(SupplierImportModel::STATUS_COMPLETED_WITH_ERRORS, $import['status']);
        $this->assertSame(5, (int) $import['total_rows']);
        $this->assertSame(3, (int) $import['created_count']);
        $this->assertSame(1, (int) $import['updated_count']);
        $this->assertSame(2, (int) $import['linked_count']);
        $this->assertSame(1, (int) $import['error_count']);

        $this->seeInDatabase('supplier_offers', ['supplier_sku' => 'NEW-SKU', 'product_id' => $bySku, 'cost_price' => '20.00']);
        $this->seeInDatabase('supplier_offers', ['supplier_sku' => 'NEW-EAN', 'product_id' => $byEan]);
        $this->seeInDatabase('supplier_offers', ['supplier_sku' => 'NEW-NONE', 'product_id' => null]);
        $this->seeInDatabase('supplier_offers', ['supplier_sku' => 'EXIST-1', 'supplier_product_name' => 'Atualizado', 'cost_price' => '11.50', 'stock_quantity' => 9]);
        $this->assertSame(4, $this->db->table('supplier_offers')->where('supplier_id', $supplierId)->countAllResults());

        $this->seeInDatabase('sync_logs', ['integration_type' => 'supplier', 'integration_id' => $supplierId, 'action' => 'import_csv', 'status' => 'warning']);
    }

    public function testInvalidFileIsRecordedAsFailed(): void
    {
        $supplierId = $this->createSupplier();
        file_put_contents($this->file, "foo;bar\n1;2\n");

        try {
            (new SupplierImportService())->importCsv($supplierId, $this->file, 'ruim.csv', null);
            $this->fail('Era esperada uma BusinessRuleException.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Colunas obrigatórias', $e->getMessage());
        }

        $this->seeInDatabase('supplier_imports', ['supplier_id' => $supplierId, 'status' => SupplierImportModel::STATUS_FAILED]);
        $this->seeInDatabase('sync_logs', ['integration_id' => $supplierId, 'status' => 'error']);
    }

    public function testReimportingSameFileIsIdempotent(): void
    {
        $supplierId = $this->createSupplier();
        file_put_contents($this->file, "supplier_sku;name;cost_price;stock_quantity\nA;Produto A;10;1\nB;Produto B;20;2\n");

        $service = new SupplierImportService();
        $service->importCsv($supplierId, $this->file, 'a.csv', null);
        $second = $service->importCsv($supplierId, $this->file, 'a.csv', null);

        $import = (new SupplierImportModel())->find($second);
        $this->assertSame(0, (int) $import['created_count']);
        $this->assertSame(2, (int) $import['updated_count']);
        $this->assertSame(2, $this->db->table('supplier_offers')->where('supplier_id', $supplierId)->countAllResults());
    }
}

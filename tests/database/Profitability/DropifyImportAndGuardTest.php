<?php

namespace Tests\Database\Profitability;

use App\DTO\Profitability\ProfitabilityResult;
use App\Exceptions\BusinessRuleException;
use App\Models\HomologationChecklistItemModel;
use App\Models\ProfitabilityAnalysisModel;
use App\Services\Profitability\HomologationChecklistService;
use App\Services\Suppliers\DropifyImportService;
use Config\Services;
use Tests\Support\DatabaseTestCase;

final class DropifyImportAndGuardTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('profitability');
        Services::resetSingle('settings');
        service('settings')->seedDefaults();
    }

    public function testImportIsIdempotentAndCreatesAnalysisRows(): void
    {
        $supplierId = $this->createSupplier([
            'name'             => 'Dropify Teste',
            'integration_type' => 'csv',
            'provider_code'    => 'dropify',
        ]);

        $fixture = ROOTPATH . 'tests/fixtures/dropify/produtos-amostra.csv';

        $importId1 = (new DropifyImportService())->importCsv($supplierId, $fixture, 'produtos.csv');
        $importId2 = (new DropifyImportService())->importCsv($supplierId, $fixture, 'produtos.csv');

        $this->assertNotSame(0, $importId1);
        $this->assertNotSame(0, $importId2);
        $this->assertSame(5, $this->db->table('products')->where('source_provider', 'dropify')->countAllResults());
        $this->assertSame(5, $this->db->table('supplier_offers')->where('supplier_id', $supplierId)->countAllResults());
        $this->assertSame(5, $this->db->table('profitability_analyses')->countAllResults());

        $this->seeInDatabase('products', ['sku' => 'DFY-1001', 'origin_uf' => 'SP']);
        $this->seeInDatabase('supplier_offers', ['supplier_sku' => '1001', 'cost_price' => '45.90']);
        $this->assertSame(
            ProfitabilityResult::AGUARDANDO_DADOS,
            (new ProfitabilityAnalysisModel())->where('product_id', $this->db->table('products')->where('sku', 'DFY-1001')->get()->getRowArray()['id'])->first()['classification'],
        );
    }

    public function testPublishGuardBlocksDropifyWithoutRentavel(): void
    {
        $supplierId = $this->createSupplier(['provider_code' => 'dropify']);
        $productId  = $this->createProduct(['sku' => 'DFY-X', 'source_provider' => 'dropify', 'sale_price' => '50.00']);
        $this->db->table('supplier_offers')->insert([
            'supplier_id'           => $supplierId,
            'product_id'            => $productId,
            'supplier_sku'          => 'X',
            'supplier_product_name' => 'X',
            'cost_price'            => '40.00',
            'stock_quantity'        => 1,
            'active'                => 1,
        ]);

        $this->db->table('profitability_analyses')->insert([
            'product_id'         => $productId,
            'classification'     => ProfitabilityResult::PREJUIZO,
            'block_reasons'      => json_encode(['Resultado de contribuição negativo ou zero.']),
            'cost_reliability'   => 'confirmado',
            'calculation_source' => 'test',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Publicação bloqueada');
        service('profitability')->assertPublishable($productId);
    }

    public function testChecklistRequiresEvidenceToConfirm(): void
    {
        $service = new HomologationChecklistService();
        $items   = $service->ensureDefaults();
        $this->assertNotEmpty($items);

        $this->expectException(BusinessRuleException::class);
        $service->updateItem((int) $items[0]['id'], HomologationChecklistItemModel::STATUS_CONFIRMED, '', null);
    }
}

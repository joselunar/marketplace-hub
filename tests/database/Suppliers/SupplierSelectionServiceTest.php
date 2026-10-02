<?php

namespace Tests\Database\Suppliers;

use App\Services\Catalog\ProductService;
use App\Services\Suppliers\SupplierOfferService;
use App\Services\Suppliers\SupplierSelectionService;
use App\Services\Suppliers\SupplierService;
use Tests\Support\DatabaseTestCase;

final class SupplierSelectionServiceTest extends DatabaseTestCase
{
    private int $productId;
    private int $alpha;
    private int $gamma;

    protected function setUp(): void
    {
        parent::setUp();

        service('settings')->seedDefaults();

        $this->productId = $this->createProduct(['sku' => 'PROD-A']);
        $this->alpha     = $this->createSupplier(['name' => 'Alpha', 'priority' => 7, 'default_shipping_days' => 2]);
        $this->gamma     = $this->createSupplier(['name' => 'Gamma', 'priority' => 6, 'default_shipping_days' => 3]);
    }

    public function testPersistsPrimaryReservesAndLogsFirstSelection(): void
    {
        $alphaOffer = $this->offer($this->alpha, ['cost_price' => '100', 'stock_quantity' => '20']);
        $gammaOffer = $this->offer($this->gamma, ['cost_price' => '105', 'stock_quantity' => '150']);

        $this->seeInDatabase('products', ['id' => $this->productId, 'selected_offer_id' => $gammaOffer]);
        $this->seeInDatabase('supplier_offers', ['id' => $gammaOffer, 'selection_rank' => 1, 'available_stock' => 148]);
        $this->seeInDatabase('supplier_offers', ['id' => $alphaOffer, 'selection_rank' => 2, 'available_stock' => 18]);

        $logs = $this->db->table('supplier_selection_logs')->orderBy('id')->get()->getResultArray();
        $this->assertCount(2, $logs, 'Uma troca ao cadastrar Alpha e outra quando Gamma passou à frente.');
        $this->assertStringContainsString('Fornecedor principal definido: Alpha', $logs[0]['reason']);
        $this->assertStringContainsString('Gamma passou a ter o maior score', $logs[1]['reason']);
        $this->assertSame('offer', $logs[1]['trigger']);
    }

    public function testSwitchesAutomaticallyWhenPrimaryRunsOutOfStock(): void
    {
        $this->offer($this->alpha, ['cost_price' => '100', 'stock_quantity' => '20']);
        $gammaOffer = $this->offer($this->gamma, ['cost_price' => '105', 'stock_quantity' => '150']);

        (new SupplierOfferService())->update($gammaOffer, $this->offerInput($this->gamma, ['cost_price' => '105', 'stock_quantity' => '0']));

        $product = $this->db->table('products')->where('id', $this->productId)->get()->getRowArray();
        $this->assertNotSame($gammaOffer, (int) $product['selected_offer_id']);

        $log = $this->lastLog();
        $this->assertSame('Gamma', $log['previous_supplier_name']);
        $this->assertSame('Alpha', $log['new_supplier_name']);
        $this->assertStringContainsString('Troca automática para Alpha', $log['reason']);
        $this->assertStringContainsString('Gamma ficou inelegível (Sem estoque no fornecedor)', $log['reason']);
        $this->seeInDatabase('audit_logs', ['action' => 'supplier_change', 'entity_type' => 'product', 'entity_id' => $this->productId]);
    }

    public function testClearsSelectionWhenNoOfferIsEligible(): void
    {
        $offerId = $this->offer($this->alpha, ['stock_quantity' => '10']);

        (new SupplierService())->update($this->alpha, [
            'name' => 'Alpha', 'integration_type' => 'manual', 'status' => 'blocked', 'priority' => '7', 'default_shipping_days' => '2',
        ]);

        $this->seeInDatabase('products', ['id' => $this->productId, 'selected_offer_id' => null]);
        $this->seeInDatabase('supplier_offers', ['id' => $offerId, 'selection_rank' => null, 'ineligibility_reason' => 'Fornecedor bloqueado']);
        $this->assertSame('Sem fornecedor elegível: Alpha ficou inelegível (Fornecedor bloqueado).', $this->lastLog()['reason']);
        $this->assertSame('supplier', $this->lastLog()['trigger']);
    }

    public function testManualBlockRequiresReasonAndExcludesOffer(): void
    {
        $alphaOffer = $this->offer($this->alpha, ['cost_price' => '50', 'stock_quantity' => '100']);
        $gammaOffer = $this->offer($this->gamma, ['cost_price' => '90', 'stock_quantity' => '10']);
        $service    = new SupplierOfferService();

        try {
            $service->update($alphaOffer, $this->offerInput($this->alpha, ['cost_price' => '50', 'stock_quantity' => '100', 'blocked' => '1']));
            $this->fail('Bloqueio sem motivo deveria ser recusado.');
        } catch (\App\Exceptions\ValidationException $e) {
            $this->assertArrayHasKey('blocked_reason', $e->getErrors());
        }

        $service->update($alphaOffer, $this->offerInput($this->alpha, [
            'cost_price' => '50', 'stock_quantity' => '100', 'blocked' => '1', 'blocked_reason' => 'Atrasos recorrentes',
        ]));

        $this->seeInDatabase('products', ['id' => $this->productId, 'selected_offer_id' => $gammaOffer]);
        $this->seeInDatabase('supplier_offers', ['id' => $alphaOffer, 'ineligibility_reason' => 'Oferta bloqueada manualmente: Atrasos recorrentes']);
    }

    public function testLogsWhenPrimaryOfferIsDeleted(): void
    {
        $alphaOffer = $this->offer($this->alpha, ['cost_price' => '50', 'stock_quantity' => '100']);
        $this->offer($this->gamma, ['cost_price' => '90', 'stock_quantity' => '10']);

        (new SupplierOfferService())->delete($alphaOffer);

        $log = $this->lastLog();
        $this->assertSame((string) $alphaOffer, $log['previous_offer_id']);
        $this->assertSame('Gamma', $log['new_supplier_name']);
        $this->assertStringContainsString('a oferta de Alpha foi excluída', $log['reason']);
    }

    public function testUnlinkingPrimaryReselectsAndResetsOffer(): void
    {
        $alphaOffer = $this->offer($this->alpha, ['cost_price' => '50', 'stock_quantity' => '100']);
        $this->offer($this->gamma, ['cost_price' => '90', 'stock_quantity' => '10']);

        (new SupplierOfferService())->link($alphaOffer, null);

        $this->assertStringContainsString('a oferta de Alpha foi desvinculada do produto', $this->lastLog()['reason']);
        $this->seeInDatabase('supplier_offers', ['id' => $alphaOffer, 'selection_rank' => null, 'selection_score' => null, 'evaluated_at' => null]);
    }

    public function testProductSafetyStockOverridesSupplierAndGlobal(): void
    {
        $this->db->table('suppliers')->where('id', $this->alpha)->update(['safety_stock' => 1]);
        $offerId = $this->offer($this->alpha, ['stock_quantity' => '5']);
        $this->seeInDatabase('supplier_offers', ['id' => $offerId, 'available_stock' => 4]);

        $product = (new ProductService())->findOrFail($this->productId);
        (new ProductService())->update($this->productId, [
            'sku' => $product['sku'], 'name' => $product['name'], 'active' => '1', 'safety_stock' => '5',
        ]);

        $this->seeInDatabase('supplier_offers', ['id' => $offerId, 'available_stock' => 0]);
        $this->seeInDatabase('products', ['id' => $this->productId, 'selected_offer_id' => null]);
        $this->assertSame('product', $this->lastLog()['trigger']);
    }

    public function testReliabilityComesFromSyncHistory(): void
    {
        $this->offer($this->alpha, ['cost_price' => '100', 'stock_quantity' => '50']);
        $this->offer($this->gamma, ['cost_price' => '100', 'stock_quantity' => '50']);
        $this->seeInDatabase('products', ['id' => $this->productId, 'selected_offer_id' => $this->offerIdFor($this->alpha)]);

        $now = date('Y-m-d H:i:s');

        foreach (['error', 'error', 'success'] as $status) {
            $this->db->table('sync_logs')->insert([
                'integration_type' => 'supplier', 'integration_id' => $this->alpha, 'action' => 'import_csv',
                'status' => $status, 'message' => 'teste', 'started_at' => $now, 'finished_at' => $now,
            ]);
        }

        $stats = service('supplierSelection')->selectForSupplier($this->alpha, SupplierSelectionService::TRIGGER_COMMAND);

        $this->assertSame(['evaluated' => 1, 'changed' => 1], $stats);
        $result = service('supplierSelection')->evaluate($this->productId);
        $this->assertSame('Gamma', $result->primary()->supplierName);
        $this->assertSame(33.33, $result->find($this->offerIdFor($this->alpha))->criteria['reliability']['score']);
    }

    public function testSelectAllUsesCurrentWeights(): void
    {
        $this->offer($this->alpha, ['cost_price' => '50', 'stock_quantity' => '60']);
        $this->offer($this->gamma, ['cost_price' => '80', 'stock_quantity' => '100']);
        $this->seeInDatabase('products', ['id' => $this->productId, 'selected_offer_id' => $this->offerIdFor($this->alpha)]);

        $settings = service('settings');
        $input    = $settings->all();
        $input['ranking.weight_price'] = '0';
        $input['ranking.weight_stock'] = '100';
        $changed = $settings->updateMany($input);

        $this->assertContains('ranking.weight_price', $changed);

        $stats = service('supplierSelection')->selectAll(SupplierSelectionService::TRIGGER_SETTINGS);

        $this->assertSame(1, $stats['changed']);
        $this->seeInDatabase('products', ['id' => $this->productId, 'selected_offer_id' => $this->offerIdFor($this->gamma)]);
    }

    public function testNoLogWhenPrimaryDoesNotChange(): void
    {
        $this->offer($this->alpha, ['stock_quantity' => '10']);
        $before = $this->db->table('supplier_selection_logs')->countAllResults();

        service('supplierSelection')->select($this->productId, SupplierSelectionService::TRIGGER_MANUAL);

        $this->assertSame($before, $this->db->table('supplier_selection_logs')->countAllResults());
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
            'supplier_product_name' => 'Produto A',
            'cost_price'            => '100',
            'stock_quantity'        => '10',
            'shipping_cost'         => '0',
            'active'                => '1',
        ];
    }

    private function offerIdFor(int $supplierId): int
    {
        return (int) $this->db->table('supplier_offers')->where('supplier_id', $supplierId)->get()->getRow()->id;
    }

    private function lastLog(): array
    {
        return $this->db->table('supplier_selection_logs')->orderBy('id', 'DESC')->get()->getRowArray();
    }
}

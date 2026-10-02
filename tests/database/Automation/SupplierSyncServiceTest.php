<?php

namespace Tests\Database\Automation;

use App\DTO\Suppliers\SupplierFeedResult;
use App\DTO\Suppliers\SupplierProductData;
use App\Jobs\SyncSupplierStockJob;
use App\Models\JobModel;
use App\Services\Automation\JobQueue;
use App\Services\Automation\Scheduler;
use App\Services\Suppliers\Connectors\SupplierConnectorFactory;
use App\Services\Suppliers\Connectors\SupplierConnectorInterface;
use App\Services\Suppliers\Connectors\UnsupportedOperationException;
use App\Services\Suppliers\SupplierOfferService;
use App\Services\Suppliers\SupplierSyncService;
use Config\Services;
use Config\SupplierConnectors;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeSupplierConnector;

final class SupplierSyncServiceTest extends DatabaseTestCase
{
    private SupplierSyncService $sync;
    private int $supplierId;
    private int $productId;
    private int $offerId;

    protected function setUp(): void
    {
        parent::setUp();
        service('settings')->seedDefaults();
        FakeSupplierConnector::reset();

        $this->supplierId = $this->createSupplier(['name' => 'Distribuidora API', 'integration_type' => 'api', 'default_shipping_days' => 2]);
        $this->createSupplier(['name' => 'Fornecedor Manual']);
        $this->productId = $this->createProduct(['sku' => 'FONE-01']);
        $this->offerId   = (new SupplierOfferService())->create([
            'supplier_id' => $this->supplierId, 'product_id' => $this->productId, 'supplier_sku' => 'API-1', 'supplier_product_name' => 'Fone',
            'cost_price' => '100', 'stock_quantity' => '10', 'shipping_cost' => '5', 'active' => '1',
        ]);

        $config           = new SupplierConnectors();
        $config->adapters = [$this->supplierId => FakeSupplierConnector::class];
        $this->sync       = new SupplierSyncService(connectors: new SupplierConnectorFactory($config));
    }

    public function testOnlyActiveSuppliersWithAdapterAreSyncable(): void
    {
        $this->assertSame([[
            'id' => $this->supplierId, 'name' => 'Distribuidora API', 'integration_type' => 'api', 'stock' => true, 'prices' => true,
        ]], $this->sync->syncable());

        $this->db->table('suppliers')->where('id', $this->supplierId)->update(['status' => 'inactive']);
        $this->assertSame([], $this->sync->syncable());
    }

    public function testStockSyncUpdatesOnlyStockOfKnownOffersAndRecalculates(): void
    {
        FakeSupplierConnector::$feed = new SupplierFeedResult([
            new SupplierProductData('API-1', 'Nome novo', '80.00', 3),
            new SupplierProductData('DESCONHECIDO', 'Outro', '10.00', 50),
        ], [], 2);

        $stats = $this->sync->sync($this->supplierId, SupplierSyncService::MODE_STOCK);

        $this->assertSame(['updated' => 1, 'skipped' => 1, 'errors' => 0, 'changed' => 0], $stats);
        $this->assertSame([SupplierConnectorInterface::CAPABILITY_FETCH_STOCK], FakeSupplierConnector::$calls);

        $offer = $this->db->table('supplier_offers')->where('id', $this->offerId)->get()->getRowArray();
        $this->assertSame(3, (int) $offer['stock_quantity']);
        $this->assertSame('100.00', $offer['cost_price'], 'sincronização de estoque não mexe no custo');
        $this->assertSame('Fone', $offer['supplier_product_name']);
        $this->assertNotNull($offer['last_sync_at']);
        $this->assertSame(1, (int) $offer['available_stock'], 'seleção recalculada: 3 − 2 de segurança');
        $this->assertSame(1, $this->db->table('supplier_offers')->countAllResults(), 'SKU desconhecido não cria oferta');

        $log = $this->db->table('sync_logs')->where('action', 'sync_stock')->get()->getRowArray();
        $this->assertSame('success', $log['status']);
        $this->assertSame((string) $this->supplierId, (string) $log['integration_id']);
    }

    public function testPriceSyncUpdatesCostAndShippingOnly(): void
    {
        FakeSupplierConnector::$feed = new SupplierFeedResult([new SupplierProductData('API-1', 'Fone', '90.50', 999, shippingCost: '7.00')], [], 1);

        $this->sync->sync($this->supplierId, SupplierSyncService::MODE_PRICES);

        $offer = $this->db->table('supplier_offers')->where('id', $this->offerId)->get()->getRowArray();
        $this->assertSame('90.50', $offer['cost_price']);
        $this->assertSame('7.00', $offer['shipping_cost']);
        $this->assertSame(10, (int) $offer['stock_quantity']);
        $this->assertNotNull($this->db->table('products')->where('id', $this->productId)->get()->getRowArray()['suggested_price']);
    }

    public function testCatalogIsUsedWhenThereIsNoSpecificOperation(): void
    {
        FakeSupplierConnector::$capabilities = [SupplierConnectorInterface::CAPABILITY_FETCH_PRODUCTS];
        FakeSupplierConnector::$feed         = new SupplierFeedResult([new SupplierProductData('API-1', 'Fone', '100.00', 4)], [], 1);

        $this->sync->sync($this->supplierId, SupplierSyncService::MODE_STOCK);

        $this->assertSame([SupplierConnectorInterface::CAPABILITY_FETCH_PRODUCTS], FakeSupplierConnector::$calls);
        $this->seeInDatabase('supplier_offers', ['id' => $this->offerId, 'stock_quantity' => 4]);
    }

    public function testFailureIsLoggedAndRethrown(): void
    {
        FakeSupplierConnector::$failure = new RuntimeException('API do fornecedor fora do ar');

        try {
            $this->sync->sync($this->supplierId, SupplierSyncService::MODE_STOCK);
            $this->fail('A falha precisa chegar a quem chamou.');
        } catch (RuntimeException $e) {
            $this->assertSame('API do fornecedor fora do ar', $e->getMessage());
        }

        $log = $this->db->table('sync_logs')->where('action', 'sync_stock')->get()->getRowArray();
        $this->assertSame('error', $log['status']);
        $this->assertStringContainsString('fora do ar', $log['message']);
    }

    public function testSupplierWithoutAdapterIsRefused(): void
    {
        $manual = (int) $this->db->table('suppliers')->where('name', 'Fornecedor Manual')->get()->getRowArray()['id'];

        $this->expectException(UnsupportedOperationException::class);
        $this->sync->sync($manual, SupplierSyncService::MODE_STOCK);
    }

    public function testScheduledTaskQueuesAndWorkerRunsTheJob(): void
    {
        Services::injectMock('supplierSync', $this->sync);
        FakeSupplierConnector::$feed = new SupplierFeedResult([new SupplierProductData('API-1', 'Fone', '100.00', 6)], [], 1);

        $result = (new Scheduler())->runNow('supplier_stock');
        $this->assertSame('1 job(s) enfileirado(s).', $result['message']);
        $this->seeInDatabase('jobs', ['type' => SyncSupplierStockJob::TYPE, 'reference' => 'Distribuidora API', 'source' => JobModel::SOURCE_MANUAL]);

        (new JobQueue())->work(10, 10);

        $job = $this->db->table('jobs')->where('type', SyncSupplierStockJob::TYPE)->get()->getRowArray();
        $this->assertSame(JobModel::STATUS_COMPLETED, $job['status'], (string) $job['last_error']);
        $this->assertStringStartsWith('1 oferta(s) atualizada(s)', $job['result']);
        $this->seeInDatabase('supplier_offers', ['id' => $this->offerId, 'stock_quantity' => 6]);
    }
}

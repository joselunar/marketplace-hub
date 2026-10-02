<?php

namespace Tests\Unit\Suppliers;

use App\Services\Suppliers\Connectors\CsvSupplierConnector;
use App\Services\Suppliers\Connectors\ManualSupplierConnector;
use App\Services\Suppliers\Connectors\SupplierConnectorFactory;
use App\Services\Suppliers\Connectors\UnsupportedOperationException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\SupplierConnectors;
use InvalidArgumentException;

final class SupplierConnectorFactoryTest extends CIUnitTestCase
{
    public function testResolvesManualConnector(): void
    {
        $connector = (new SupplierConnectorFactory())->make(['id' => 1, 'integration_type' => 'manual']);

        $this->assertInstanceOf(ManualSupplierConnector::class, $connector);
        $this->assertTrue($connector->testConnection()->success);
    }

    public function testResolvesCsvConnectorWithFile(): void
    {
        $connector = (new SupplierConnectorFactory())->make(['id' => 1, 'integration_type' => 'csv'], ['file_path' => '/tmp/x.csv']);

        $this->assertInstanceOf(CsvSupplierConnector::class, $connector);
    }

    public function testCsvConnectorRequiresFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SupplierConnectorFactory())->make(['id' => 1, 'integration_type' => 'csv']);
    }

    public function testApiSupplierWithoutAdapterIsRejectedInsteadOfFaked(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessage('nenhum adapter');

        (new SupplierConnectorFactory())->make(['id' => 9, 'name' => 'XYZ', 'integration_type' => 'api']);
    }

    public function testRegisteredAdapterTakesPrecedence(): void
    {
        $config           = new SupplierConnectors();
        $config->adapters = [9 => ManualSupplierConnector::class];

        $connector = (new SupplierConnectorFactory($config))->make(['id' => 9, 'integration_type' => 'api']);

        $this->assertInstanceOf(ManualSupplierConnector::class, $connector);
    }
}

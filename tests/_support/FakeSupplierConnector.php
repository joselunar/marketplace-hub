<?php

namespace Tests\Support;

use App\DTO\Suppliers\SupplierFeedResult;
use App\Services\Suppliers\Connectors\AbstractSupplierConnector;
use App\Services\Suppliers\Connectors\SupplierConnectorInterface;
use App\Services\Suppliers\Connectors\UnsupportedOperationException;
use Throwable;

/**
 * Adapter de fornecedor para testes: devolve o feed configurado ou lança a
 * falha configurada. A fábrica instancia a classe, por isso o estado é estático.
 */
final class FakeSupplierConnector extends AbstractSupplierConnector
{
    /**
     * @var list<string>
     */
    public static array $capabilities = [SupplierConnectorInterface::CAPABILITY_FETCH_STOCK, SupplierConnectorInterface::CAPABILITY_FETCH_PRICES];

    public static ?SupplierFeedResult $feed = null;
    public static ?Throwable $failure      = null;

    /**
     * @var list<string>
     */
    public static array $calls = [];

    public function __construct(array $supplier = [], array $options = [])
    {
    }

    public static function reset(): void
    {
        self::$capabilities = [SupplierConnectorInterface::CAPABILITY_FETCH_STOCK, SupplierConnectorInterface::CAPABILITY_FETCH_PRICES];
        self::$feed         = null;
        self::$failure      = null;
        self::$calls        = [];
    }

    protected function capabilities(): array
    {
        return self::$capabilities;
    }

    public function fetchStock(): SupplierFeedResult
    {
        return $this->answer(self::CAPABILITY_FETCH_STOCK);
    }

    public function fetchPrices(): SupplierFeedResult
    {
        return $this->answer(self::CAPABILITY_FETCH_PRICES);
    }

    public function fetchProducts(): SupplierFeedResult
    {
        return $this->answer(self::CAPABILITY_FETCH_PRODUCTS);
    }

    private function answer(string $capability): SupplierFeedResult
    {
        if (! $this->supports($capability)) {
            throw UnsupportedOperationException::for(self::class, $capability);
        }

        self::$calls[] = $capability;

        if (self::$failure !== null) {
            throw self::$failure;
        }

        return self::$feed ?? new SupplierFeedResult([], [], 0);
    }
}

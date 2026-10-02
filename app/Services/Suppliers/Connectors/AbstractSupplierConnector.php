<?php

namespace App\Services\Suppliers\Connectors;

use App\DTO\Suppliers\ConnectionTestResult;
use App\DTO\Suppliers\SupplierFeedResult;

/**
 * Base que recusa explicitamente as operações não implementadas,
 * para que nenhum conector "finja" suportar algo.
 */
abstract class AbstractSupplierConnector implements SupplierConnectorInterface
{
    /**
     * @return list<string>
     */
    abstract protected function capabilities(): array;

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function testConnection(): ConnectionTestResult
    {
        throw UnsupportedOperationException::for(static::class, self::CAPABILITY_TEST_CONNECTION);
    }

    public function fetchProducts(): SupplierFeedResult
    {
        throw UnsupportedOperationException::for(static::class, self::CAPABILITY_FETCH_PRODUCTS);
    }

    public function fetchStock(): SupplierFeedResult
    {
        throw UnsupportedOperationException::for(static::class, self::CAPABILITY_FETCH_STOCK);
    }

    public function fetchPrices(): SupplierFeedResult
    {
        throw UnsupportedOperationException::for(static::class, self::CAPABILITY_FETCH_PRICES);
    }

    public function createOrder(array $order): array
    {
        throw UnsupportedOperationException::for(static::class, self::CAPABILITY_CREATE_ORDER);
    }

    public function getOrderStatus(string $externalOrderId): array
    {
        throw UnsupportedOperationException::for(static::class, self::CAPABILITY_ORDER_STATUS);
    }
}

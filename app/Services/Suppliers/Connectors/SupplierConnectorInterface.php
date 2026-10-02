<?php

namespace App\Services\Suppliers\Connectors;

use App\DTO\Suppliers\ConnectionTestResult;
use App\DTO\Suppliers\SupplierFeedResult;

/**
 * Contrato comum para integração com fornecedores.
 *
 * Cada fornecedor real terá o próprio adapter (ex.: FornecedorXyzConnector),
 * baseado na documentação oficial que ele fornecer. Nem todo conector
 * implementa todas as operações: consulte supports() antes de chamar.
 */
interface SupplierConnectorInterface
{
    public const CAPABILITY_TEST_CONNECTION = 'test_connection';
    public const CAPABILITY_FETCH_PRODUCTS  = 'fetch_products';
    public const CAPABILITY_FETCH_STOCK     = 'fetch_stock';
    public const CAPABILITY_FETCH_PRICES    = 'fetch_prices';
    public const CAPABILITY_CREATE_ORDER    = 'create_order';
    public const CAPABILITY_ORDER_STATUS    = 'order_status';

    public function supports(string $capability): bool;

    public function testConnection(): ConnectionTestResult;

    /**
     * Catálogo completo (dados cadastrais + custo + estoque).
     */
    public function fetchProducts(): SupplierFeedResult;

    /**
     * Apenas estoque atualizado, quando o fornecedor oferece endpoint/arquivo específico.
     */
    public function fetchStock(): SupplierFeedResult;

    /**
     * Apenas custos atualizados, quando o fornecedor oferece endpoint/arquivo específico.
     */
    public function fetchPrices(): SupplierFeedResult;

    /**
     * Envia um pedido ao fornecedor. O formato definitivo será definido na fase de pedidos.
     *
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    public function createOrder(array $order): array;

    /**
     * @return array<string, mixed>
     */
    public function getOrderStatus(string $externalOrderId): array;
}

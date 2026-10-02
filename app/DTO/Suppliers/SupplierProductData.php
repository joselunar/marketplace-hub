<?php

namespace App\DTO\Suppliers;

/**
 * Produto/oferta como informado por um fornecedor, já validado e normalizado
 * pelo conector. Valores monetários em string decimal ("123.45").
 */
final readonly class SupplierProductData
{
    public function __construct(
        public string $supplierSku,
        public string $name,
        public string $costPrice,
        public int $stockQuantity,
        public ?string $ean = null,
        public ?string $brand = null,
        public ?string $shippingCost = null,
        public ?int $shippingDays = null,
        public ?string $productSku = null,
    ) {
    }
}

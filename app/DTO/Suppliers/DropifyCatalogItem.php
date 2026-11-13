<?php

namespace App\DTO\Suppliers;

/**
 * Item do catálogo Dropify (CSV hoje; API no futuro), já normalizado.
 */
final readonly class DropifyCatalogItem
{
    /**
     * @param list<string> $imageUrls
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $supplierSku,
        public string $name,
        public string $costPrice,
        public int $stockQuantity,
        public ?string $suggestedPrice = null,
        public ?string $ean = null,
        public ?string $brand = null,
        public ?string $description = null,
        public ?string $shortDescription = null,
        public ?string $sourceCategory = null,
        public ?int $weightGrams = null,
        public ?string $heightCm = null,
        public ?string $widthCm = null,
        public ?string $lengthCm = null,
        public ?int $shippingDays = null,
        public ?string $ncm = null,
        public ?string $cest = null,
        public ?string $fiscalOrigin = null,
        public ?string $originUf = null,
        public ?string $originPostalCode = null,
        public ?string $availabilityStatus = null,
        public array $imageUrls = [],
        public array $metadata = [],
    ) {
    }

    public function toSupplierProductData(): SupplierProductData
    {
        return new SupplierProductData(
            supplierSku: $this->supplierSku,
            name: $this->name,
            costPrice: $this->costPrice,
            stockQuantity: $this->stockQuantity,
            ean: $this->ean,
            brand: $this->brand,
            shippingCost: null,
            shippingDays: $this->shippingDays,
            productSku: 'DFY-' . $this->supplierSku,
        );
    }
}

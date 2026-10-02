<?php

namespace App\DTO\MercadoLivre;

use App\Models\MarketplaceListingModel;

/**
 * Dados de um anúncio prontos para virar o corpo de POST /items.
 */
final class ListingDraft
{
    /**
     * @param list<string>          $pictures   URLs públicas das imagens
     * @param array<string, string> $attributes id do atributo => valor
     */
    public function __construct(
        public readonly string $sellingModel,
        public readonly string $categoryId,
        public readonly string $listingTypeId,
        public readonly string $condition,
        public readonly string $currencyId,
        public readonly string $price,
        public readonly int $quantity,
        public readonly ?string $title = null,
        public readonly ?string $familyName = null,
        public readonly array $pictures = [],
        public readonly array $attributes = [],
        public readonly ?string $warrantyType = null,
        public readonly ?string $warrantyTime = null,
    ) {
    }

    public function isUserProducts(): bool
    {
        return $this->sellingModel === MarketplaceListingModel::MODEL_USER_PRODUCTS;
    }
}

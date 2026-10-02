<?php

namespace App\DTO\MercadoLivre;

use App\DTO\Pricing\PriceBreakdown;
use App\DTO\Suppliers\OfferEvaluation;

/**
 * Tudo o que a tela de prévia mostra antes da confirmação: o que será
 * enviado ao Mercado Livre, de onde veio cada valor e o que impede a
 * publicação.
 */
final class ListingPreview
{
    /**
     * @param list<array<string, mixed>>  $predictions    sugestões de categoria pelo nome do produto
     * @param list<array<string, mixed>>  $attributes     atributos exibidos no formulário
     * @param array<string, string>       $attributeValues
     * @param list<array<string, mixed>>  $listingTypes
     * @param list<string>                $conditions
     * @param list<string>                $pictures
     * @param list<string>                $errors         impedem a publicação
     * @param list<string>                $warnings
     */
    public function __construct(
        public readonly array $product,
        public readonly array $account,
        public readonly string $sellingModel,
        public readonly ?array $existing,
        public readonly ?OfferEvaluation $offer,
        public readonly int $quantity,
        public readonly ?array $category,
        public readonly ?string $categorySource,
        public readonly array $predictions,
        public readonly array $attributes,
        public readonly array $attributeValues,
        public readonly array $listingTypes,
        public readonly ?string $listingTypeId,
        public readonly array $conditions,
        public readonly string $condition,
        public readonly ?string $currencyId,
        public readonly string $title,
        public readonly string $familyName,
        public readonly ?int $maxTitleLength,
        public readonly array $pictures,
        public readonly ?string $warrantyType,
        public readonly ?string $warrantyTime,
        public readonly ?ListingFee $fee,
        public readonly ?PriceBreakdown $breakdown,
        public readonly ?ListingDraft $draft,
        public readonly ?array $payload,
        public readonly array $errors,
        public readonly array $warnings,
    ) {
    }

    public function canPublish(): bool
    {
        return $this->errors === [] && $this->draft !== null;
    }

    public function price(): ?string
    {
        return $this->breakdown?->price;
    }
}

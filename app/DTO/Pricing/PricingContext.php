<?php

namespace App\DTO\Pricing;

/**
 * O que o motor de seleção precisa saber sobre o preço de um produto
 * para aplicar o critério de margem mínima a cada oferta.
 */
final class PricingContext
{
    public function __construct(
        public readonly PricingConfig $config,
        public readonly MarginPolicy $margins,
        public readonly ?string $manualPrice = null,
        public readonly string $productExtraCost = '0.00',
    ) {
    }

    public function hasManualPrice(): bool
    {
        return $this->manualPrice !== null;
    }
}

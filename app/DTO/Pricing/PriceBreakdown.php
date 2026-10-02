<?php

namespace App\DTO\Pricing;

/**
 * Composição de um preço de venda: cada custo, o lucro e a margem resultante.
 *
 * Todos os valores são strings decimais com duas casas.
 */
final class PriceBreakdown
{
    public const MODE_SUGGESTED = 'suggested';
    public const MODE_MANUAL    = 'manual';

    public function __construct(
        public readonly string $mode,
        public readonly string $price,
        public readonly string $costPrice,
        public readonly string $shippingCost,
        public readonly string $extraFixedCost,
        public readonly string $marketplaceFee,
        public readonly string $marketplaceFixedFee,
        public readonly string $taxAmount,
        public readonly string $extraPercentageCost,
        public readonly string $totalCosts,
        public readonly string $profit,
        public readonly string $marginPercentage,
        public readonly MarginPolicy $margins,
        public readonly PricingConfig $config,
        public readonly ?string $suggestedPrice,
        public readonly ?string $minimumPrice,
        public readonly ?string $rawPrice = null,
    ) {
    }

    public function isManual(): bool
    {
        return $this->mode === self::MODE_MANUAL;
    }

    public function isBelowMinimum(): bool
    {
        return bccomp($this->marginPercentage, $this->margins->minimum, 2) < 0;
    }

    /**
     * Comissão percentual + tarifa fixa.
     */
    public function marketplaceTotal(): string
    {
        return bcadd($this->marketplaceFee, $this->marketplaceFixedFee, 2);
    }

    /**
     * Impostos e custos extras (fixos e percentuais).
     */
    public function otherCosts(): string
    {
        return bcadd(bcadd($this->taxAmount, $this->extraFixedCost, 2), $this->extraPercentageCost, 2);
    }

    public function toArray(): array
    {
        return [
            'mode'                  => $this->mode,
            'price'                 => $this->price,
            'cost_price'            => $this->costPrice,
            'shipping_cost'         => $this->shippingCost,
            'extra_fixed_cost'      => $this->extraFixedCost,
            'marketplace_fee'       => $this->marketplaceFee,
            'marketplace_fixed_fee' => $this->marketplaceFixedFee,
            'tax_amount'            => $this->taxAmount,
            'extra_percentage_cost' => $this->extraPercentageCost,
            'total_costs'           => $this->totalCosts,
            'profit'                => $this->profit,
            'margin_percentage'     => $this->marginPercentage,
            'below_minimum'         => $this->isBelowMinimum(),
            'suggested_price'       => $this->suggestedPrice,
            'minimum_price'         => $this->minimumPrice,
            'margins'               => $this->margins->toArray(),
            'rates'                 => [
                'marketplace_fee_percentage' => $this->config->marketplaceFeePercentage,
                'tax_percentage'             => $this->config->taxPercentage,
                'extra_cost_percentage'      => $this->config->extraCostPercentage,
                'rounding'                   => $this->config->rounding,
            ],
        ];
    }
}

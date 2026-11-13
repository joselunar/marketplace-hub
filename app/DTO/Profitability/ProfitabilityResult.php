<?php

namespace App\DTO\Profitability;

final readonly class ProfitabilityResult
{
    public const RENTAVEL         = 'rentavel';
    public const MARGEM_BAIXA     = 'margem_baixa';
    public const PREJUIZO         = 'prejuizo';
    public const AGUARDANDO_DADOS = 'aguardando_dados';

    public const LABELS = [
        self::RENTAVEL         => 'Rentável',
        self::MARGEM_BAIXA     => 'Margem baixa',
        self::PREJUIZO         => 'Prejuízo',
        self::AGUARDANDO_DADOS => 'Aguardando dados',
    ];

    /**
     * @param list<CostLine> $lines
     * @param list<string>   $blockReasons
     * @param list<string>   $unknownEssential
     */
    public function __construct(
        public string $classification,
        public string $salePrice,
        public string $costPrice,
        public ?string $marketplaceFeeTotal,
        public string $marketplaceFeeReliability,
        public ?string $sellerShippingCost,
        public string $sellerShippingReliability,
        public string $variableCostsTotal,
        public string $contributionMargin,
        public string $contributionMarginPct,
        public string $operatingResult,
        public ?string $minimumViablePrice,
        public ?int $breakEvenUnits,
        public string $costReliability,
        public array $lines,
        public array $blockReasons,
        public array $unknownEssential,
        public string $fixedCostPerUnit,
        public string $monthlyFixedCosts,
        public string $indicativeMargin = '0.00',
        public string $indicativeMarginPct = '0.00',
    ) {
    }

    public function label(): string
    {
        return self::LABELS[$this->classification] ?? $this->classification;
    }

    public function isPublishable(): bool
    {
        return $this->classification === self::RENTAVEL && $this->blockReasons === [];
    }

    public function toArray(): array
    {
        return [
            'classification'              => $this->classification,
            'classification_label'        => $this->label(),
            'sale_price'                  => $this->salePrice,
            'cost_price'                  => $this->costPrice,
            'marketplace_fee_total'       => $this->marketplaceFeeTotal,
            'marketplace_fee_reliability' => $this->marketplaceFeeReliability,
            'seller_shipping_cost'        => $this->sellerShippingCost,
            'seller_shipping_reliability' => $this->sellerShippingReliability,
            'variable_costs_total'        => $this->variableCostsTotal,
            'contribution_margin'         => $this->contributionMargin,
            'contribution_margin_pct'     => $this->contributionMarginPct,
            'operating_result'            => $this->operatingResult,
            'minimum_viable_price'        => $this->minimumViablePrice,
            'break_even_units'            => $this->breakEvenUnits,
            'cost_reliability'            => $this->costReliability,
            'fixed_cost_per_unit'         => $this->fixedCostPerUnit,
            'monthly_fixed_costs'         => $this->monthlyFixedCosts,
            'lines'                       => array_map(static fn (CostLine $l): array => $l->toArray(), $this->lines),
            'block_reasons'               => $this->blockReasons,
            'unknown_essential'           => $this->unknownEssential,
            'indicative_margin'           => $this->indicativeMargin,
            'indicative_margin_pct'       => $this->indicativeMarginPct,
            'publishable'                 => $this->isPublishable(),
        ];
    }
}

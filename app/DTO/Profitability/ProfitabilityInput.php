<?php

namespace App\DTO\Profitability;

/**
 * Entrada pura do motor de rentabilidade (sem I/O).
 * Valores monetários em string decimal.
 */
final readonly class ProfitabilityInput
{
    /**
     * @param list<CostLine> $extraLines linhas opcionais já tipadas
     */
    public function __construct(
        public string $salePrice,
        public string $costPrice,
        public ?string $marketplaceFeeTotal,
        public string $marketplaceFeeReliability,
        public string $marketplaceFeeReason,
        public ?string $sellerShippingCost,
        public string $sellerShippingReliability,
        public string $sellerShippingReason,
        public string $minimumMarginPercentage,
        public string $returnsReservePercentage = '0.00',
        public string $variableOpsPercentage = '0.00',
        public string $variableOpsFixed = '0.00',
        public string $taxPercentage = '0.00',
        public bool $taxApproved = false,
        public string $monthlyFixedCosts = '0.00',
        public int $monthlyUnitsAssumption = 30,
        public array $extraLines = [],
    ) {
    }
}

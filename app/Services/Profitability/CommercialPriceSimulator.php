<?php

namespace App\Services\Profitability;

use App\DTO\Profitability\CostLine;
use App\DTO\Profitability\ProfitabilityInput;
use App\Support\Decimal;

/**
 * Simula preços-alvo por margem, reconsultando tarifas ML a cada preço.
 */
class CommercialPriceSimulator
{
    /** @var list<string> */
    public const DEFAULT_TARGETS = ['10.00', '15.00', '20.00', '25.00', '30.00'];

    public function __construct(
        private readonly ProfitabilityEngine $engine = new ProfitabilityEngine(),
        private readonly MercadoLivreCostReader $mlCosts = new MercadoLivreCostReader(),
    ) {
    }

    /**
     * @param array<string, mixed>      $productContext
     * @param array<string, mixed>|null $offer
     * @param list<string>              $targets
     *
     * @return list<array<string, mixed>>
     */
    public function simulateTargets(
        array $productContext,
        string $costPrice,
        ?array $offer,
        array $settingsSnapshot,
        array $targets = self::DEFAULT_TARGETS,
        ?int $accountId = null,
    ): array {
        $out = [];
        foreach ($targets as $target) {
            $target = Decimal::round((string) $target, 2);
            $out[]  = $this->solveForMargin($productContext, $costPrice, $offer, $settingsSnapshot, $target, $accountId);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $productContext
     * @param array<string, mixed>|null $offer
     * @param array<string, mixed> $settingsSnapshot
     *
     * @return array<string, mixed>
     */
    private function solveForMargin(
        array $productContext,
        string $costPrice,
        ?array $offer,
        array $settingsSnapshot,
        string $targetMarginPct,
        ?int $accountId,
    ): array {
        $price = $this->seedPrice($costPrice, $targetMarginPct);
        $best  = null;

        for ($round = 0; $round < 8; $round++) {
            $quotes = $this->mlCosts->quote($productContext, $price, null, $accountId, $offer);
            $fee    = $this->mlCosts->bestFee($quotes);
            $ship   = $quotes['shipping'];

            $input = new ProfitabilityInput(
                salePrice: $price,
                costPrice: $costPrice,
                marketplaceFeeTotal: $fee['amount'],
                marketplaceFeeReliability: $fee['reliability'],
                marketplaceFeeReason: $fee['reason'],
                sellerShippingCost: $ship['amount'],
                sellerShippingReliability: $ship['reliability'],
                sellerShippingReason: $ship['reason'] ?? '',
                minimumMarginPercentage: $targetMarginPct,
                returnsReservePercentage: (string) $settingsSnapshot['returns'],
                variableOpsPercentage: (string) $settingsSnapshot['ops_pct'],
                variableOpsFixed: (string) $settingsSnapshot['ops_fixed'],
                taxPercentage: (string) $settingsSnapshot['tax_pct'],
                taxApproved: (bool) $settingsSnapshot['tax_approved'],
                monthlyFixedCosts: (string) $settingsSnapshot['fixed_monthly'],
                monthlyUnitsAssumption: (int) $settingsSnapshot['units'],
            );

            $result = $this->engine->analyze($input);
            $indPct = $result->indicativeMarginPct;
            $best   = [
                'target_margin_pct'           => $targetMarginPct,
                'sale_price'                  => $price,
                'marketplace_fee_total'       => $result->marketplaceFeeTotal,
                'marketplace_fee_reliability' => $result->marketplaceFeeReliability,
                'seller_shipping_cost'        => $result->sellerShippingCost,
                'seller_shipping_reliability' => $result->sellerShippingReliability,
                'indicative_margin'           => $result->indicativeMargin,
                'indicative_margin_pct'       => $indPct,
                'contribution_margin'         => $result->contributionMargin,
                'contribution_margin_pct'     => $result->contributionMarginPct,
                'classification'              => $result->classification,
                'unknown_essential'           => $result->unknownEssential,
                'listing_type'                => $fee['listing_type'] ?? null,
                'classic_fee'                 => is_array($quotes['classic'] ?? null) ? ($quotes['classic']['sale_fee_amount'] ?? null) : null,
                'premium_fee'                 => is_array($quotes['premium'] ?? null) ? ($quotes['premium']['sale_fee_amount'] ?? null) : null,
                'quoted_at'                   => $quotes['quoted_at'] ?? date('Y-m-d H:i:s'),
                'guarantee'                   => $result->unknownEssential === [] ? 'confirmado' : 'nao_garantido',
                'note'                        => $result->unknownEssential === []
                    ? 'Margem com custos essenciais confirmados.'
                    : 'Margem indicativa — há custos essenciais estimados/desconhecidos (não tratar como garantida).',
                'rounds'                      => $round + 1,
            ];

            if ($fee['amount'] === null || ($ship['amount'] === null && ($ship['reliability'] ?? '') !== CostLine::CONFIRMED)) {
                $best['sale_price'] = null;
                $best['note']       = 'Não foi possível fechar o preço-alvo: tarifa ou frete indisponíveis para a cotação.';
                break;
            }

            $delta = bcsub($indPct, $targetMarginPct, 2);
            if (bccomp(self::abs($delta), '0.35', 2) <= 0) {
                break;
            }

            // Ajusta preço proporcionalmente à diferença de margem.
            $factor = bccomp($indPct, '0', 2) === 0
                ? '1.08'
                : bcdiv(bcadd('100', $targetMarginPct, 4), bcadd('100', $indPct, 4), 6);
            if (bccomp($factor, '0.5', 4) < 0) {
                $factor = '0.5';
            }
            if (bccomp($factor, '2.5', 4) > 0) {
                $factor = '2.5';
            }
            $price = Decimal::round(bcmul($price, $factor, 6), 2);
            if (bccomp($price, $costPrice, 2) <= 0) {
                $price = Decimal::round(bcmul($costPrice, '1.5', 4), 2);
            }
        }

        return $best ?? [
            'target_margin_pct' => $targetMarginPct,
            'sale_price'        => null,
            'guarantee'         => 'nao_garantido',
            'note'              => 'Falha na simulação.',
        ];
    }

    private function seedPrice(string $cost, string $targetMarginPct): string
    {
        // Parte de custo * 2.2 e sobe com a margem-alvo (antes da 1ª cotação ML).
        $base = bcmul($cost, '2.20', 4);
        $lift = bcadd('1', bcdiv($targetMarginPct, '100', 4), 4);

        return Decimal::round(bcmul($base, $lift, 4), 2);
    }

    private static function abs(string $value): string
    {
        return bccomp($value, '0', 4) < 0 ? bcmul($value, '-1', 4) : $value;
    }
}

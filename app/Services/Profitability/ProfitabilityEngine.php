<?php

namespace App\Services\Profitability;

use App\DTO\Profitability\CostLine;
use App\DTO\Profitability\ProfitabilityInput;
use App\DTO\Profitability\ProfitabilityResult;
use App\Support\Decimal;

/**
 * Motor puro de rentabilidade (sem I/O).
 *
 * Custos essenciais para classificar como rentável: custo do fornecedor,
 * tarifa total do marketplace e frete assumido pelo vendedor — todos com
 * valor numérico e confiabilidade "confirmado" (ou frete confirmado zero).
 */
final class ProfitabilityEngine
{
    public function analyze(ProfitabilityInput $input): ProfitabilityResult
    {
        $sale = Decimal::round($input->salePrice, 2);
        $cost = Decimal::round($input->costPrice, 2);

        $lines   = [];
        $unknown = [];

        $lines[] = new CostLine('cost_price', 'Custo do fornecedor', $cost, CostLine::CONFIRMED);

        $feeAmount = $input->marketplaceFeeTotal;
        $feeRel    = $input->marketplaceFeeReliability;
        if ($feeRel === CostLine::UNKNOWN || $feeAmount === null) {
            $unknown[] = 'tarifa_marketplace';
            $lines[]   = new CostLine('marketplace_fee', 'Tarifa Mercado Livre (total)', null, CostLine::UNKNOWN, $input->marketplaceFeeReason);
            $feeAmount = null;
        } else {
            $feeAmount = Decimal::round($feeAmount, 2);
            $lines[]   = new CostLine('marketplace_fee', 'Tarifa Mercado Livre (total)', $feeAmount, $feeRel, $input->marketplaceFeeReason);
            if ($feeRel !== CostLine::CONFIRMED) {
                $unknown[] = 'tarifa_marketplace';
            }
        }

        $shipAmount = $input->sellerShippingCost;
        $shipRel    = $input->sellerShippingReliability;
        if ($shipRel === CostLine::UNKNOWN || ($shipAmount === null && $shipRel !== CostLine::CONFIRMED)) {
            $unknown[]  = 'frete_vendedor';
            $shipAmount = null;
            $lines[]    = new CostLine('seller_shipping', 'Frete/logística do vendedor', null, CostLine::UNKNOWN, $input->sellerShippingReason);
        } else {
            $shipAmount = Decimal::round($shipAmount ?? '0.00', 2);
            $lines[]    = new CostLine('seller_shipping', 'Frete/logística do vendedor', $shipAmount, $shipRel, $input->sellerShippingReason);
            if ($shipRel !== CostLine::CONFIRMED) {
                $unknown[] = 'frete_vendedor';
            }
        }

        $returnsPct = Decimal::round($input->returnsReservePercentage, 2);
        $returns    = bccomp($returnsPct, '0', 2) > 0
            ? Decimal::round(bcdiv(bcmul($sale, $returnsPct, 4), '100', 4), 2)
            : '0.00';
        $lines[] = new CostLine(
            'returns_reserve',
            'Reserva devoluções/imprevistos',
            $returns,
            CostLine::ESTIMATED,
            'Parâmetro configurável (' . $returnsPct . '% do preço).',
        );

        $opsPct   = Decimal::round($input->variableOpsPercentage, 2);
        $opsFixed = Decimal::round($input->variableOpsFixed, 2);
        $opsPctAmt = bccomp($opsPct, '0', 2) > 0
            ? Decimal::round(bcdiv(bcmul($sale, $opsPct, 4), '100', 4), 2)
            : '0.00';
        $opsTotal = Decimal::round(bcadd($opsPctAmt, $opsFixed, 4), 2);
        $lines[]  = new CostLine(
            'variable_ops',
            'Custos operacionais variáveis',
            $opsTotal,
            bccomp($opsTotal, '0', 2) === 0 ? CostLine::SKIPPED : CostLine::ESTIMATED,
            'Configuração do painel (não vem da API).',
        );

        $taxAmount = '0.00';
        if ($input->taxApproved && bccomp($input->taxPercentage, '0', 2) > 0) {
            $taxAmount = Decimal::round(bcdiv(bcmul($sale, Decimal::round($input->taxPercentage, 2), 4), '100', 4), 2);
            $lines[]   = new CostLine('tax', 'Tributos estimados', $taxAmount, CostLine::ESTIMATED, 'Regra configurada e marcada como aprovada pelo contador.');
        } else {
            $lines[] = new CostLine(
                'tax',
                'Tributos estimados',
                null,
                CostLine::SKIPPED,
                $input->taxApproved
                    ? 'Percentual zerado na configuração.'
                    : 'Tributos não aplicados: aguardam aprovação do contador no painel.',
            );
        }

        foreach ($input->extraLines as $extra) {
            $lines[] = $extra;
            if ($extra->reliability === CostLine::UNKNOWN) {
                $unknown[] = $extra->code;
            }
        }

        $variableTotal = $cost;
        foreach ([$feeAmount, $shipAmount, $returns, $opsTotal, $taxAmount] as $part) {
            if ($part !== null) {
                $variableTotal = bcadd($variableTotal, $part, 2);
            }
        }

        $hasUnknownEssential = $unknown !== [];
        $margin              = $hasUnknownEssential ? '0.00' : Decimal::round(bcsub($sale, $variableTotal, 4), 2);
        $marginPct           = (! $hasUnknownEssential && bccomp($sale, '0', 2) > 0)
            ? Decimal::round(bcmul(bcdiv($margin, $sale, 6), '100', 6), 2)
            : '0.00';

        $units       = max(1, $input->monthlyUnitsAssumption);
        $fixedMonth  = Decimal::round($input->monthlyFixedCosts, 2);
        $fixedUnit   = Decimal::round(bcdiv($fixedMonth, (string) $units, 4), 2);
        $operating   = $hasUnknownEssential ? '0.00' : Decimal::round(bcsub($margin, $fixedUnit, 4), 2);

        $breakEven = null;
        if (! $hasUnknownEssential && bccomp($margin, '0', 2) > 0) {
            $breakEven = (int) ceil((float) bcdiv($fixedMonth, $margin, 6));
        }

        $minPrice = null;
        if (! $hasUnknownEssential) {
            $minPrice = $this->approximateMinimumPrice($input);
        }

        $classification = $this->classify($hasUnknownEssential, $margin, $marginPct, $input->minimumMarginPercentage);
        $reliability    = $this->overallReliability($lines, $hasUnknownEssential);
        $blocks         = $this->blockReasons($classification, $hasUnknownEssential, $unknown);

        return new ProfitabilityResult(
            classification: $classification,
            salePrice: $sale,
            costPrice: $cost,
            marketplaceFeeTotal: $feeAmount,
            marketplaceFeeReliability: $feeRel === CostLine::UNKNOWN ? CostLine::UNKNOWN : $feeRel,
            sellerShippingCost: $shipAmount,
            sellerShippingReliability: $shipRel === CostLine::UNKNOWN ? CostLine::UNKNOWN : $shipRel,
            variableCostsTotal: $hasUnknownEssential ? Decimal::round($variableTotal, 2) : Decimal::round($variableTotal, 2),
            contributionMargin: $margin,
            contributionMarginPct: $marginPct,
            operatingResult: $operating,
            minimumViablePrice: $minPrice,
            breakEvenUnits: $breakEven,
            costReliability: $reliability,
            lines: $lines,
            blockReasons: $blocks,
            unknownEssential: array_values(array_unique($unknown)),
            fixedCostPerUnit: $fixedUnit,
            monthlyFixedCosts: $fixedMonth,
        );
    }

    private function classify(bool $waiting, string $margin, string $marginPct, string $minimum): string
    {
        if ($waiting) {
            return ProfitabilityResult::AGUARDANDO_DADOS;
        }

        if (bccomp($margin, '0', 2) <= 0) {
            return ProfitabilityResult::PREJUIZO;
        }

        if (bccomp($marginPct, Decimal::round($minimum, 2), 2) < 0) {
            return ProfitabilityResult::MARGEM_BAIXA;
        }

        return ProfitabilityResult::RENTAVEL;
    }

    /**
     * @param list<CostLine> $lines
     */
    private function overallReliability(array $lines, bool $hasUnknownEssential): string
    {
        if ($hasUnknownEssential) {
            return CostLine::UNKNOWN;
        }

        foreach ($lines as $line) {
            if ($line->reliability === CostLine::ESTIMATED) {
                return CostLine::ESTIMATED;
            }
        }

        return CostLine::CONFIRMED;
    }

    /**
     * @param list<string> $unknown
     *
     * @return list<string>
     */
    private function blockReasons(string $classification, bool $waiting, array $unknown): array
    {
        $reasons = [];

        if ($waiting) {
            $map = [
                'tarifa_marketplace' => 'Tarifa do Mercado Livre ausente ou não confirmada.',
                'frete_vendedor'     => 'Custo de frete/logística do vendedor ausente ou não confirmado.',
            ];
            foreach ($unknown as $code) {
                $reasons[] = $map[$code] ?? ('Custo essencial desconhecido: ' . $code);
            }
        }

        if ($classification === ProfitabilityResult::PREJUIZO) {
            $reasons[] = 'Resultado de contribuição negativo ou zero.';
        }

        if ($classification === ProfitabilityResult::MARGEM_BAIXA) {
            $reasons[] = 'Margem de contribuição abaixo do mínimo configurado.';
        }

        return $reasons;
    }

    /**
     * Aproxima o preço mínimo para a margem desejada assumindo que a tarifa
     * percentual + reservas escalam com o preço, e a tarifa fixa embutida no
     * total informado permanece constante. Se a tarifa total veio como valor
     * fechado, decompõe percentual implícito = (fee - 0) / preço atual.
     */
    private function approximateMinimumPrice(ProfitabilityInput $input): ?string
    {
        $sale = Decimal::round($input->salePrice, 2);
        $fee  = $input->marketplaceFeeTotal;
        if ($fee === null || bccomp($sale, '0', 2) <= 0) {
            return null;
        }

        $feePct = bcdiv(bcmul($fee, '100', 6), $sale, 6);
        $ship   = Decimal::round($input->sellerShippingCost ?? '0.00', 2);
        $cost   = Decimal::round($input->costPrice, 2);
        $opsF   = Decimal::round($input->variableOpsFixed, 2);

        $variablePct = bcadd(
            bcadd($feePct, Decimal::round($input->returnsReservePercentage, 2), 4),
            Decimal::round($input->variableOpsPercentage, 2),
            4,
        );
        if ($input->taxApproved) {
            $variablePct = bcadd($variablePct, Decimal::round($input->taxPercentage, 2), 4);
        }

        $targetMargin = Decimal::round($input->minimumMarginPercentage, 2);
        $denomPct     = bcsub('100', bcadd($variablePct, $targetMargin, 4), 4);
        if (bccomp($denomPct, '0', 4) <= 0) {
            return null;
        }

        $fixedPart = bcadd(bcadd($cost, $ship, 2), $opsF, 2);
        $raw       = bcdiv(bcmul($fixedPart, '100', 6), $denomPct, 6);

        return Decimal::round($raw, 2);
    }
}

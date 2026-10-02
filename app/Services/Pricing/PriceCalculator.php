<?php

namespace App\Services\Pricing;

use App\DTO\Pricing\CostInput;
use App\DTO\Pricing\MarginPolicy;
use App\DTO\Pricing\PriceBreakdown;
use App\DTO\Pricing\PricingConfig;
use App\Support\Decimal;

/**
 * Cálculo de preço de venda. Não acessa o banco.
 *
 * Margem = lucro / preço de venda. Comissão do marketplace, impostos e custos
 * extras percentuais incidem sobre o preço, então o preço necessário para uma
 * margem M é:
 *
 *     preço = custos fixos / (1 − (comissão + impostos + extras% + M) / 100)
 *
 * onde custos fixos = custo + frete + custos extras fixos (+ tarifa fixa do
 * marketplace quando o preço fica abaixo da faixa configurada).
 *
 * O arredondamento é sempre para cima, para nunca reduzir a margem.
 */
class PriceCalculator
{
    private const SCALE = 6;

    /**
     * Preço sugerido para a margem desejada (ou a mínima, se maior), já arredondado.
     */
    public function suggest(CostInput $costs, MarginPolicy $margins, PricingConfig $config): PriceBreakdown
    {
        $raw     = $this->priceForMargin($margins->target(), $costs, $config);
        $price   = $this->round($raw, $config->rounding);
        $minimum = $this->priceForMargin($margins->minimum, $costs, $config);

        return $this->breakdown(PriceBreakdown::MODE_SUGGESTED, $price, $costs, $margins, $config, $price, $minimum, $raw);
    }

    /**
     * Composição de um preço definido manualmente.
     */
    public function at(string $price, CostInput $costs, MarginPolicy $margins, PricingConfig $config): PriceBreakdown
    {
        if (! is_numeric($price) || bccomp($price, '0', 2) <= 0) {
            throw new PricingException('O preço de venda precisa ser maior que zero.');
        }

        $price   = Decimal::round($price, 2);
        $minimum = $this->priceForMargin($margins->minimum, $costs, $config);

        try {
            $suggested = $this->round($this->priceForMargin($margins->target(), $costs, $config), $config->rounding);
        } catch (PricingException) {
            // Margem desejada inviável não impede avaliar o preço manual contra a mínima.
            $suggested = null;
        }

        return $this->breakdown(PriceBreakdown::MODE_MANUAL, $price, $costs, $margins, $config, $suggested, $minimum);
    }

    /**
     * Menor preço (em centavos inteiros, sem a regra de arredondamento)
     * que garante a margem informada.
     */
    public function priceForMargin(string $margin, CostInput $costs, PricingConfig $config): string
    {
        $percentages = bcadd($config->percentageCosts(), $margin, 2);
        $divisor     = bcsub('100', $percentages, 2);

        if (bccomp($divisor, '0', 2) <= 0) {
            throw new PricingException(sprintf(
                'Comissão (%s%%), impostos (%s%%), custos extras (%s%%) e margem (%s%%) somam %s%%; não existe preço que cubra os custos.',
                $this->percent($config->marketplaceFeePercentage),
                $this->percent($config->taxPercentage),
                $this->percent($config->extraCostPercentage),
                $this->percent($margin),
                $this->percent($percentages),
            ));
        }

        $fixed      = $this->fixedCosts($costs, $config);
        $withoutFee = $this->solve($fixed, $divisor);
        $fixedFee   = $config->marketplaceFixedFee;

        if (bccomp($fixedFee, '0', 2) === 0) {
            return $this->ceilCents($withoutFee);
        }

        $threshold = $config->fixedFeeThreshold;
        $withFee   = $this->solve(bcadd($fixed, $fixedFee, 2), $divisor);

        if (bccomp($threshold, '0', 2) === 0) {
            return $this->ceilCents($withFee);
        }

        if (bccomp($withoutFee, $threshold, self::SCALE) >= 0) {
            return $this->ceilCents($withoutFee);
        }

        if (bccomp($withFee, $threshold, self::SCALE) < 0) {
            return $this->ceilCents($withFee);
        }

        // Com a tarifa o preço passaria da faixa; sem ela, ficaria abaixo.
        // No limite da faixa a tarifa deixa de ser cobrada e a margem é atendida.
        return Decimal::round($threshold, 2);
    }

    /**
     * Aplica a regra de arredondamento (sempre para cima).
     */
    public function round(string $price, string $rule): string
    {
        $cents = (int) bcmul($this->ceilCents($price), '100', 0);
        $units = intdiv($cents, 100);

        $rounded = match ($rule) {
            PricingConfig::ROUNDING_INTEGER   => $cents % 100 === 0 ? $cents : ($units + 1) * 100,
            PricingConfig::ROUNDING_ENDING_90 => $this->ending($cents, 90),
            PricingConfig::ROUNDING_ENDING_99 => $this->ending($cents, 99),
            default                           => $cents,
        };

        return bcdiv((string) $rounded, '100', 2);
    }

    public function fixedFeeApplies(string $price, PricingConfig $config): bool
    {
        if (bccomp($config->marketplaceFixedFee, '0', 2) === 0) {
            return false;
        }

        return bccomp($config->fixedFeeThreshold, '0', 2) === 0 || bccomp($price, $config->fixedFeeThreshold, 2) < 0;
    }

    private function breakdown(
        string $mode,
        string $price,
        CostInput $costs,
        MarginPolicy $margins,
        PricingConfig $config,
        ?string $suggested,
        ?string $minimum,
        ?string $raw = null,
    ): PriceBreakdown {
        $cost       = Decimal::round($costs->costPrice, 2);
        $shipping   = Decimal::round($costs->shippingCost, 2);
        $extraFixed = Decimal::round(bcadd($config->extraFixedCost, $costs->productExtraCost, 2), 2);
        $fee        = $this->share($price, $config->marketplaceFeePercentage);
        $fixedFee   = $this->fixedFeeApplies($price, $config) ? Decimal::round($config->marketplaceFixedFee, 2) : '0.00';
        $tax        = $this->share($price, $config->taxPercentage);
        $extraPct   = $this->share($price, $config->extraCostPercentage);

        $total = '0';

        foreach ([$cost, $shipping, $extraFixed, $fee, $fixedFee, $tax, $extraPct] as $amount) {
            $total = bcadd($total, $amount, 2);
        }

        $profit = bcsub($price, $total, 2);
        $margin = Decimal::round(bcdiv(bcmul($profit, '100', self::SCALE), $price, self::SCALE), 2);

        return new PriceBreakdown(
            mode: $mode,
            price: $price,
            costPrice: $cost,
            shippingCost: $shipping,
            extraFixedCost: $extraFixed,
            marketplaceFee: $fee,
            marketplaceFixedFee: $fixedFee,
            taxAmount: $tax,
            extraPercentageCost: $extraPct,
            totalCosts: $total,
            profit: $profit,
            marginPercentage: $margin,
            margins: $margins,
            config: $config,
            suggestedPrice: $suggested,
            minimumPrice: $minimum,
            rawPrice: $raw,
        );
    }

    private function fixedCosts(CostInput $costs, PricingConfig $config): string
    {
        return bcadd(bcadd(bcadd($costs->costPrice, $costs->shippingCost, 2), $costs->productExtraCost, 2), $config->extraFixedCost, 2);
    }

    private function solve(string $fixed, string $divisor): string
    {
        return bcdiv(bcmul($fixed, '100', self::SCALE), $divisor, self::SCALE);
    }

    private function share(string $price, string $percentage): string
    {
        return Decimal::round(bcdiv(bcmul($price, $percentage, self::SCALE), '100', self::SCALE), 2);
    }

    /**
     * Arredonda para o centavo de cima ("10.001" → "10.01").
     */
    private function ceilCents(string $value): string
    {
        $cents = bcmul($value, '100', self::SCALE);
        $whole = bcadd($cents, '0', 0);

        if (bccomp($cents, $whole, self::SCALE) > 0) {
            $whole = bcadd($whole, '1', 0);
        }

        return bcdiv($whole, '100', 2);
    }

    /**
     * Menor valor terminado em ",XX" que não fique abaixo do preço.
     */
    private function ending(int $cents, int $ending): int
    {
        $candidate = intdiv($cents, 100) * 100 + $ending;

        return $candidate >= $cents ? $candidate : $candidate + 100;
    }

    private function percent(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ',');
    }
}

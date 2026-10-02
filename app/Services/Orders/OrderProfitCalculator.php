<?php

namespace App\Services\Orders;

use App\DTO\Pricing\PricingConfig;
use App\Support\Decimal;

/**
 * Lucro estimado de um item vendido. Não acessa o banco.
 *
 * lucro = receita − tarifa do marketplace − (custo + frete do fornecedor
 *         + custos extras fixos) × quantidade − impostos% − extras%
 *
 * Impostos e custos extras vêm das mesmas configurações da precificação.
 * O frete pago pelo vendedor ao marketplace não entra: ele não vem na order.
 */
class OrderProfitCalculator
{
    private const SCALE = 6;

    /**
     * Tarifa do item quando a order ainda não informa sale_fee (antes da
     * acreditação do pagamento): usa a comissão gravada no anúncio ou, sem
     * anúncio, a das configurações.
     *
     * @param array{sale_fee_percentage?: string|null, sale_fixed_fee?: string|null}|null $listing
     */
    public function estimatedFee(string $unitPrice, int $quantity, ?array $listing, PricingConfig $config): string
    {
        if ($listing !== null && ($listing['sale_fee_percentage'] ?? null) !== null) {
            $percentage = (string) $listing['sale_fee_percentage'];
            $fixed      = (string) ($listing['sale_fixed_fee'] ?? '0');
        } else {
            $percentage = $config->marketplaceFeePercentage;
            $applies    = bccomp($config->marketplaceFixedFee, '0', 2) > 0
                && (bccomp($config->fixedFeeThreshold, '0', 2) === 0 || bccomp($unitPrice, $config->fixedFeeThreshold, 2) < 0);
            $fixed = $applies ? $config->marketplaceFixedFee : '0';
        }

        $unit = bcadd($this->share($unitPrice, $percentage), $fixed, self::SCALE);

        return Decimal::round(bcmul($unit, (string) $quantity, self::SCALE), 2);
    }

    /**
     * @param string $saleFee tarifa total do item (todas as unidades)
     */
    public function itemProfit(
        string $unitPrice,
        int $quantity,
        string $saleFee,
        string $supplierCost,
        string $supplierShipping,
        string $productExtraCost,
        PricingConfig $config,
    ): string {
        $qty     = (string) $quantity;
        $revenue = bcmul($unitPrice, $qty, self::SCALE);
        $unit    = bcadd(bcadd(bcadd($supplierCost, $supplierShipping, self::SCALE), $productExtraCost, self::SCALE), $config->extraFixedCost, self::SCALE);
        $costs   = bcmul($unit, $qty, self::SCALE);
        $rates   = bcadd($config->taxPercentage, $config->extraCostPercentage, self::SCALE);

        $profit = bcsub($revenue, $saleFee, self::SCALE);
        $profit = bcsub($profit, $costs, self::SCALE);
        $profit = bcsub($profit, $this->share($revenue, $rates), self::SCALE);

        return Decimal::round($profit, 2);
    }

    /**
     * Margem em % sobre o valor vendido; null sem lucro calculado ou sem venda.
     */
    public function margin(?string $profit, ?string $revenue): ?string
    {
        if ($profit === null || $revenue === null || bccomp($revenue, '0', 2) <= 0) {
            return null;
        }

        return Decimal::round(bcdiv(bcmul($profit, '100', self::SCALE), $revenue, self::SCALE), 2);
    }

    private function share(string $amount, string $percentage): string
    {
        return bcdiv(bcmul($amount, $percentage, self::SCALE), '100', self::SCALE);
    }
}

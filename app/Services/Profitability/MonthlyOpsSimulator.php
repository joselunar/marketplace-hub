<?php

namespace App\Services\Profitability;

use App\Support\Decimal;

/**
 * Simulação operacional mensal (cenários de volume).
 */
class MonthlyOpsSimulator
{
    /** @var list<int> */
    public const DEFAULT_VOLUMES = [10, 20, 30, 50, 100];

    /**
     * @param list<int> $volumes
     *
     * @return array{scenarios: list<array<string, mixed>>, mei: array<string, mixed>, assumptions: array<string, mixed>}
     */
    public function simulate(
        string $salePrice,
        string $variableCostPerUnit,
        string $monthlyFixedCosts,
        string $workingCapitalPerUnit,
        array $volumes = self::DEFAULT_VOLUMES,
        string $meiAnnualLimit = '81000.00',
        string $meiAlertPct = '80.00',
    ): array {
        $salePrice = Decimal::round($salePrice, 2);
        $variableCostPerUnit = Decimal::round($variableCostPerUnit, 2);
        $monthlyFixedCosts = Decimal::round($monthlyFixedCosts, 2);
        $workingCapitalPerUnit = Decimal::round($workingCapitalPerUnit, 2);
        $unitContribution = Decimal::round(bcsub($salePrice, $variableCostPerUnit, 4), 2);

        $breakEvenUnits = null;
        if (bccomp($unitContribution, '0', 2) > 0) {
            $breakEvenUnits = (int) ceil((float) bcdiv($monthlyFixedCosts, $unitContribution, 6));
        }

        $scenarios = [];
        foreach ($volumes as $units) {
            $units = max(0, (int) $units);
            $revenue = Decimal::round(bcmul($salePrice, (string) $units, 4), 2);
            $variable = Decimal::round(bcmul($variableCostPerUnit, (string) $units, 4), 2);
            $contribution = Decimal::round(bcsub($revenue, $variable, 4), 2);
            $operating = Decimal::round(bcsub($contribution, $monthlyFixedCosts, 4), 2);
            $capital = Decimal::round(bcmul($workingCapitalPerUnit, (string) $units, 4), 2);
            $annualized = Decimal::round(bcmul($revenue, '12', 4), 2);

            $scenarios[] = [
                'units'                 => $units,
                'gross_revenue'         => $revenue,
                'variable_costs'        => $variable,
                'contribution_margin'   => $contribution,
                'fixed_costs'           => $monthlyFixedCosts,
                'operating_result'      => $operating,
                'working_capital'       => $capital,
                'annualized_revenue'    => $annualized,
                'covers_fixed'          => bccomp($contribution, $monthlyFixedCosts, 2) >= 0,
            ];
        }

        return [
            'scenarios'   => $scenarios,
            'break_even_units' => $breakEvenUnits,
            'unit'        => [
                'sale_price'            => $salePrice,
                'variable_cost'         => $variableCostPerUnit,
                'contribution'          => $unitContribution,
                'working_capital_unit'  => $workingCapitalPerUnit,
            ],
            'mei'         => $this->meiStatus(
                $scenarios[array_key_last($scenarios)]['annualized_revenue'] ?? '0.00',
                $meiAnnualLimit,
                $meiAlertPct,
            ),
            'assumptions' => [
                'fixed_monthly'   => $monthlyFixedCosts,
                'mei_annual_limit'=> Decimal::round($meiAnnualLimit, 2),
                'mei_alert_pct'   => Decimal::round($meiAlertPct, 2),
                'tax_note'        => 'Simulação sem presumir tributação não validada pelo contador.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meiStatus(string $annualizedRevenue, string $limit, string $alertPct): array
    {
        $limit = Decimal::round($limit, 2);
        $alert = Decimal::round(bcdiv(bcmul($limit, Decimal::round($alertPct, 2), 4), '100', 4), 2);
        $ratio = bccomp($limit, '0', 2) > 0
            ? Decimal::round(bcmul(bcdiv($annualizedRevenue, $limit, 6), '100', 6), 2)
            : '0.00';

        $level = 'ok';
        if (bccomp($annualizedRevenue, $limit, 2) >= 0) {
            $level = 'exceeded';
        } elseif (bccomp($annualizedRevenue, $alert, 2) >= 0) {
            $level = 'warning';
        }

        return [
            'annual_limit'        => $limit,
            'alert_threshold'     => $alert,
            'sample_annualized'   => $annualizedRevenue,
            'utilization_pct'     => $ratio,
            'level'               => $level,
            'message'             => match ($level) {
                'exceeded' => 'Atenção: a projeção anualizada do cenário maior atingiria ou ultrapassaria o teto MEI configurado.',
                'warning'  => 'Atenção: a projeção anualizada se aproxima do teto MEI configurado (' . $alertPct . '% do limite).',
                default    => 'Projeção anualizada do maior cenário abaixo do alerta MEI configurado.',
            },
        ];
    }
}

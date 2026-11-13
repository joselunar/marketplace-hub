<?php

namespace App\Services\Profitability;

use App\Services\Settings\SettingsService;
use App\Support\Decimal;

/**
 * Soma custos fixos sem duplicar linhas e separa operação × compartilhado (VPS).
 */
class FixedCostsPlanner
{
    public function __construct(
        private readonly ?SettingsService $settings = null,
    ) {
    }

    /**
     * @return array{
     *   lines: array<string, array{label: string, amount: string, bucket: string}>,
     *   operation_total: string,
     *   shared_total: string,
     *   company_total: string,
     *   vps_shared: bool,
     *   das_mei: string,
     *   das_configured: bool
     * }
     */
    public function breakdown(): array
    {
        $settings = $this->settings ?? service('settings');
        $vpsShared = $settings->getBool('fixed_costs.vps_shared');

        $lines = [
            'dropify' => [
                'label'  => 'Mensalidade Dropify',
                'amount' => Decimal::round((string) $settings->get('fixed_costs.dropify_monthly'), 2),
                'bucket' => 'operation',
            ],
            'fiscal_issuer' => [
                'label'  => 'Emissor fiscal',
                'amount' => Decimal::round((string) $settings->get('fixed_costs.fiscal_issuer_monthly'), 2),
                'bucket' => 'operation',
            ],
            'certificate_a1' => [
                'label'  => 'Provisão certificado A1',
                'amount' => Decimal::round((string) $settings->get('fixed_costs.certificate_a1_monthly'), 2),
                'bucket' => 'operation',
            ],
            'das_mei' => [
                'label'  => 'DAS-MEI',
                'amount' => Decimal::round((string) $settings->get('fixed_costs.das_mei_monthly'), 2),
                'bucket' => 'operation',
            ],
            'other' => [
                'label'  => 'Outros fixos da operação',
                'amount' => Decimal::round((string) $settings->get('fixed_costs.other_monthly'), 2),
                'bucket' => 'operation',
            ],
            'vps' => [
                'label'  => $vpsShared ? 'VPS Hostinger (compartilhada)' : 'VPS Hostinger (operação)',
                'amount' => Decimal::round((string) $settings->get('fixed_costs.vps_monthly'), 2),
                'bucket' => $vpsShared ? 'shared' : 'operation',
            ],
        ];

        $operation = '0.00';
        $shared = '0.00';
        foreach ($lines as $line) {
            if ($line['bucket'] === 'shared') {
                $shared = bcadd($shared, $line['amount'], 2);
            } else {
                $operation = bcadd($operation, $line['amount'], 2);
            }
        }

        $das = $lines['das_mei']['amount'];

        return [
            'lines'            => $lines,
            'operation_total'  => Decimal::round($operation, 2),
            'shared_total'     => Decimal::round($shared, 2),
            'company_total'    => Decimal::round(bcadd($operation, $shared, 2), 2),
            'vps_shared'       => $vpsShared,
            'das_mei'          => $das,
            'das_configured'   => bccomp($das, '0', 2) > 0,
        ];
    }

    /**
     * Total usado no rateio/PE da empresa (operação + compartilhados).
     */
    public function companyTotal(): string
    {
        return $this->breakdown()['company_total'];
    }
}

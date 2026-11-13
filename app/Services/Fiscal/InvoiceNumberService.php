<?php

namespace App\Services\Fiscal;

use App\Exceptions\BusinessRuleException;
use App\Models\FiscalCompanyModel;

/**
 * Reserva série/número sem duplicar. Homologação usa faixa separada
 * para não consumir numeração de produção.
 */
class InvoiceNumberService
{
    public function __construct(
        private readonly FiscalCompanyModel $companies = new FiscalCompanyModel(),
        private readonly FiscalGate $gate = new FiscalGate(),
        private readonly ?\App\Services\Settings\SettingsService $settings = null,
    ) {
    }

    /**
     * @return array{serie: int, number: int, environment: string}
     */
    public function reserve(int $companyId): array
    {
        $db = db_connect();
        $db->transStart();

        $company = $db->table('fiscal_companies')->where('id', $companyId)->get()->getRowArray();
        if ($company === null) {
            $db->transRollback();
            throw new BusinessRuleException('Empresa fiscal não encontrada.');
        }

        $environment = $this->gate->effectiveEnvironment();
        if ($environment === FiscalGate::ENV_PROD) {
            if (! $this->gate->realEmissionAuthorized()) {
                $db->transRollback();
                throw new BusinessRuleException('Não é permitido reservar numeração de produção sem autorização.');
            }
            $serie  = (int) $company['serie_nfe'];
            $number = (int) $company['next_number_nfe'];
            $db->table('fiscal_companies')->where('id', $companyId)->update([
                'next_number_nfe' => $number + 1,
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);
        } else {
            $settings = $this->settings ?? service('settings');
            $serie  = (int) $settings->get('fiscal.homolog_serie', 900);
            $number = (int) $settings->get('fiscal.homolog_next_number', 1);
            $settings->putMany(['fiscal.homolog_next_number' => (string) ($number + 1)]);
        }

        $db->transComplete();
        if (! $db->transStatus()) {
            throw new BusinessRuleException('Falha ao reservar numeração fiscal.');
        }

        return [
            'serie'       => $serie,
            'number'      => $number,
            'environment' => $environment,
        ];
    }
}

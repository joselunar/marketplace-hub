<?php

namespace App\Services\Fiscal;

use App\Exceptions\BusinessRuleException;
use App\Models\FiscalCompanyModel;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Tools;

/**
 * Monta Tools do NFePHP a partir dos dados da empresa e do A1.
 * Implementação independente do Venus (não copia código proprietário).
 */
class NfeToolsFactory
{
    public function __construct(
        private readonly FiscalCompanyModel $companies = new FiscalCompanyModel(),
        private readonly CertificateStorageService $certificates = new CertificateStorageService(),
        private readonly FiscalGate $gate = new FiscalGate(),
    ) {
    }

    public function make(?int $companyId = null): Tools
    {
        if (! class_exists(Tools::class)) {
            throw new BusinessRuleException('Biblioteca nfephp-org/sped-nfe não está instalada.');
        }
        if (! extension_loaded('soap')) {
            throw new BusinessRuleException('Extensão PHP soap é obrigatória para comunicar com a SEFAZ.');
        }
        if (! extension_loaded('openssl')) {
            throw new BusinessRuleException('Extensão PHP openssl é obrigatória para o certificado A1.');
        }

        $company = $companyId !== null
            ? $this->companies->find($companyId)
            : $this->companies->primaryCompany();
        if ($company === null) {
            throw new BusinessRuleException('Cadastre os dados fiscais da empresa antes de emitir.');
        }

        $certData = $this->certificates->loadActive((int) $company['id']);
        $config = $this->configJson($company);

        $certificate = Certificate::readPfx($certData['content'], $certData['password']);

        return new Tools($config, $certificate);
    }

    /**
     * @param array<string, mixed> $company
     */
    private function configJson(array $company): string
    {
        $uf = strtoupper((string) ($company['uf'] ?? 'RN'));
        $tpAmb = $this->gate->tpAmb();

        $payload = [
            'atualizacao' => date('Y-m-d H:i:s'),
            'tpAmb'       => $tpAmb,
            'razaosocial' => (string) $company['legal_name'],
            'cnpj'        => preg_replace('/\D+/', '', (string) $company['document']) ?? '',
            'siglaUF'     => $uf,
            'schemes'     => 'PL_009_V4',
            'versao'      => '4.00',
            'tokenIBPT'   => '',
            'CSC'         => '',
            'CSCid'       => '',
            'proxyConf'   => [
                'proxyIp'   => '',
                'proxyPort' => '',
                'proxyUser' => '',
                'proxyPass' => '',
            ],
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}

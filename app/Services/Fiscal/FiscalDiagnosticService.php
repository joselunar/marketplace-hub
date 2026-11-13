<?php

namespace App\Services\Fiscal;

use App\Models\FiscalCertificateModel;
use App\Models\FiscalCompanyModel;
use Composer\InstalledVersions;

/**
 * Diagnóstico administrativo do emissor NF-e.
 * Não interpreta cadastros locais como credenciamento SEFAZ.
 */
class FiscalDiagnosticService
{
    /** @var list<string> */
    private const REQUIRED_EXTENSIONS = [
        'soap', 'openssl', 'dom', 'simplexml', 'json', 'zlib', 'libxml', 'mbstring', 'curl',
    ];

    public function __construct(
        private readonly FiscalGate $gate = new FiscalGate(),
        private readonly FiscalCompanyModel $companies = new FiscalCompanyModel(),
        private readonly FiscalCertificateModel $certificates = new FiscalCertificateModel(),
        private readonly ?\App\Services\Settings\SettingsService $settings = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $settings = $this->settings ?? service('settings');
        $company  = $this->companies->primaryCompany();
        $cert     = $company !== null
            ? $this->certificates->activeForCompany((int) $company['id'])
            : null;

        $libraries = $this->libraries();
        $extensions = $this->extensions();
        try {
            (new FiscalDocumentStorage())->ensureBaseDirectories();
        } catch (\Throwable) {
            // Continua o relatório mesmo se a criação falhar; storage() reporta o estado.
        }
        $storage = $this->storage();
        $companyReport = $this->companyReport($company);
        $taxReport = $this->taxReport($company);
        $certReport = $this->certificateReport($company, $cert);
        $accreditation = $this->accreditationReport($settings);
        $environment = [
            'configured' => $this->gate->configuredEnvironment(),
            'effective'  => $this->gate->effectiveEnvironment(),
            'tpAmb'      => $this->gate->tpAmb(),
            'module_enabled' => $this->gate->moduleEnabled(),
            'real_emission_authorized' => $this->gate->realEmissionAuthorized(),
            'ml_xml_send_authorized' => $this->gate->mlSendAuthorized(),
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
        ];
        $homolog = $this->homologReadiness($libraries, $extensions, $companyReport, $taxReport, $certReport, $accreditation, $environment);
        $blockers = $this->blockers($libraries, $extensions, $companyReport, $taxReport, $certReport, $accreditation, $environment, $storage);

        return [
            'generated_at'  => date('c'),
            'libraries'     => $libraries,
            'extensions'    => $extensions,
            'storage'       => $storage,
            'environment'   => $environment,
            'company'       => $companyReport,
            'tax'           => $taxReport,
            'certificate'   => $certReport,
            'accreditation' => $accreditation,
            'homologation'  => $homolog,
            'blockers'      => $blockers,
            'ready_for_homolog_tests' => $homolog['ready'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function libraries(): array
    {
        $versions = [];
        foreach (['nfephp-org/sped-nfe', 'nfephp-org/sped-da', 'nfephp-org/sped-common'] as $pkg) {
            $versions[$pkg] = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($pkg)
                ? InstalledVersions::getPrettyVersion($pkg)
                : null;
        }

        $schemesDir = ROOTPATH . 'vendor/nfephp-org/sped-nfe/schemes/PL_009_V4';
        $schemaOk = is_dir($schemesDir);

        return [
            'sped_nfe_installed' => class_exists(\NFePHP\NFe\Tools::class),
            'make_available'     => class_exists(\NFePHP\NFe\Make::class),
            'danfe_available'    => class_exists(\NFePHP\DA\NFe\Danfe::class),
            'certificate_class'  => class_exists(\NFePHP\Common\Certificate::class),
            'versions'           => $versions,
            'schemes_pl009_v4'   => $schemaOk,
            'schemes_path'       => $schemaOk ? $schemesDir : null,
        ];
    }

    /**
     * @return array{items: array<string, bool>, soap: bool, soap_client: bool, all_ok: bool}
     */
    private function extensions(): array
    {
        $items = [];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $items[$ext] = extension_loaded($ext);
        }

        return [
            'items'       => $items,
            'soap'        => extension_loaded('soap'),
            'soap_client' => class_exists(\SoapClient::class),
            'all_ok'      => ! in_array(false, $items, true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storage(): array
    {
        $base = WRITEPATH . 'fiscal';
        $dirs = [
            'base'         => $base,
            'certificates' => $base . DIRECTORY_SEPARATOR . 'certificates',
            'xml'          => $base . DIRECTORY_SEPARATOR . 'xml',
            'danfe'        => $base . DIRECTORY_SEPARATOR . 'danfe',
        ];
        $report = [];
        foreach ($dirs as $key => $path) {
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            $report[$key] = [
                'path'      => $path,
                'exists'    => $exists,
                'writable'  => $writable,
                // Não expõe permissões numéricas em ambientes Windows locais; na VPS ajuda.
                'ok'        => $exists && $writable,
            ];
        }

        return $report;
    }

    /**
     * @param array<string, mixed>|null $company
     * @return array<string, mixed>
     */
    private function companyReport(?array $company): array
    {
        if ($company === null) {
            return ['configured' => false, 'complete' => false, 'missing' => ['cadastro da empresa']];
        }

        $required = [
            'legal_name' => 'razão social',
            'document'   => 'CNPJ/CPF',
            'uf'         => 'UF',
            'city_ibge'  => 'código IBGE do município',
            'street'     => 'logradouro',
            'district'   => 'bairro',
            'city'       => 'cidade',
            'zip_code'   => 'CEP',
            'crt'        => 'CRT',
        ];
        $missing = [];
        foreach ($required as $field => $label) {
            if ($company[$field] === null || $company[$field] === '') {
                $missing[] = $label;
            }
        }

        return [
            'configured' => true,
            'complete'   => $missing === [],
            'missing'    => $missing,
            'legal_name' => (string) $company['legal_name'],
            'document'   => (string) $company['document'],
            'uf'         => (string) ($company['uf'] ?? ''),
            'serie_nfe'  => (int) ($company['serie_nfe'] ?? 0),
            'next_number_nfe' => (int) ($company['next_number_nfe'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed>|null $company
     * @return array<string, mixed>
     */
    private function taxReport(?array $company): array
    {
        if ($company === null) {
            return ['present' => false, 'validated' => false, 'missing_keys' => ['empresa']];
        }
        $tax = json_decode((string) ($company['tax_params_json'] ?? '{}'), true) ?: [];
        $needed = ['cfop', 'csosn', 'natureza_operacao', 'ncm_default'];
        $missing = [];
        foreach ($needed as $key) {
            if (empty($tax[$key])) {
                $missing[] = $key;
            }
        }

        return [
            'present'      => $tax !== [],
            'validated'    => ! empty($company['tax_params_validated']),
            'validated_at' => $company['tax_params_validated_at'] ?? null,
            'missing_keys' => $missing,
            // Não lista valores tributários no diagnóstico público além das chaves ausentes.
            'keys_filled'  => array_values(array_filter($needed, static fn ($k) => ! empty($tax[$k]))),
        ];
    }

    /**
     * @param array<string, mixed>|null $company
     * @param array<string, mixed>|null $cert
     * @return array<string, mixed>
     */
    private function certificateReport(?array $company, ?array $cert): array
    {
        if ($cert === null) {
            return [
                'present' => false,
                'compatible' => false,
                'message' => 'Nenhum certificado A1 ativo. A interface em Empresa fiscal está pronta para receber o .pfx/.p12.',
            ];
        }

        $fileOk = is_file((string) $cert['storage_path']);
        $expired = false;
        if (! empty($cert['valid_to'])) {
            $expired = strtotime((string) $cert['valid_to']) < time();
        }

        $document = preg_replace('/\D+/', '', (string) ($company['document'] ?? '')) ?? '';
        // Metadados já gravados; não relê a senha no diagnóstico.
        $subject = (string) ($cert['subject'] ?? '');

        return [
            'present'      => true,
            'file_ok'      => $fileOk,
            'label'        => (string) ($cert['label'] ?? 'A1'),
            'subject'      => $subject !== '' ? $subject : null,
            'valid_from'   => $cert['valid_from'] ?? null,
            'valid_to'     => $cert['valid_to'] ?? null,
            'expired'      => $expired,
            'company_document' => $document !== '' ? $document : null,
            'cnpj_checked_on_upload' => true,
            'compatible'   => $fileOk && ! $expired,
            'message'      => $expired
                ? 'Certificado vencido. Substitua pelo A1 vigente na tela Empresa fiscal.'
                : ($fileOk
                    ? 'Certificado ativo encontrado (senha cifrada; arquivo fora do webroot).'
                    : 'Registro do certificado existe, mas o arquivo não está no disco.'),
            // Nunca retorna storage_path completo em produção? Útil para admin; mas sem senha.
            'storage_private' => str_starts_with((string) ($cert['storage_path'] ?? ''), WRITEPATH . 'fiscal'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accreditationReport(\App\Services\Settings\SettingsService $settings): array
    {
        return [
            'declared_by_admin' => $settings->getBool('fiscal.accreditation_declared', false),
            'note'              => (string) $settings->get('fiscal.accreditation_note', ''),
            'disclaimer'        => 'Declaração administrativa apenas. Não comprova credenciamento junto à SEFAZ/RN.',
        ];
    }

    /**
     * @param array<string, mixed> $libraries
     * @param array<string, mixed> $extensions
     * @param array<string, mixed> $company
     * @param array<string, mixed> $tax
     * @param array<string, mixed> $cert
     * @param array<string, mixed> $accreditation
     * @param array<string, mixed> $environment
     * @return array<string, mixed>
     */
    private function homologReadiness(
        array $libraries,
        array $extensions,
        array $company,
        array $tax,
        array $cert,
        array $accreditation,
        array $environment,
    ): array {
        $checks = [
            'nfephp'       => ! empty($libraries['sped_nfe_installed']) && ! empty($libraries['schemes_pl009_v4']),
            'soap'         => ! empty($extensions['soap']) && ! empty($extensions['soap_client']),
            'extensions'   => ! empty($extensions['all_ok']),
            'company'      => ! empty($company['complete']),
            'tax_validated'=> ! empty($tax['validated']) && ($tax['missing_keys'] ?? []) === [],
            'certificate'  => ! empty($cert['compatible']),
            'homolog_env'  => ($environment['effective'] ?? '') === FiscalGate::ENV_HOMOLOG,
            'real_blocked' => empty($environment['real_emission_authorized']),
            'ml_blocked'   => empty($environment['ml_xml_send_authorized']),
        ];

        $pending = [];
        if (! $checks['certificate']) {
            $pending[] = 'Instalar certificado e-CNPJ A1 na interface Empresa fiscal.';
        }
        if (! $checks['tax_validated']) {
            $pending[] = 'Contador deve informar e validar CFOP/CSOSN/NCM/natureza (venda à ordem/dropshipping).';
        }
        if (! $checks['company']) {
            $pending[] = 'Completar cadastro fiscal da empresa (incluindo IBGE e CRT).';
        }
        if (! $accreditation['declared_by_admin']) {
            $pending[] = 'Registrar declaração de credenciamento estadual (informativo; a SEFAZ não é consultada aqui).';
        }

        $ready = $checks['nfephp'] && $checks['soap'] && $checks['extensions']
            && $checks['company'] && $checks['tax_validated'] && $checks['certificate']
            && $checks['homolog_env'] && $checks['real_blocked'] && $checks['ml_blocked'];

        return [
            'ready'   => $ready,
            'checks'  => $checks,
            'pending' => $pending,
            'note'    => 'Testes de transmissão SEFAZ só devem ocorrer em homologação, com A1 e regras validadas. Emissão real permanece bloqueada.',
        ];
    }

    /**
     * @return list<string>
     */
    private function blockers(
        array $libraries,
        array $extensions,
        array $company,
        array $tax,
        array $cert,
        array $accreditation,
        array $environment,
        array $storage,
    ): array {
        $out = [];
        if (empty($libraries['sped_nfe_installed'])) {
            $out[] = 'Biblioteca nfephp-org/sped-nfe ausente.';
        }
        if (empty($extensions['soap'])) {
            $out[] = 'Extensão PHP soap indisponível neste processo.';
        }
        foreach ($extensions['items'] as $ext => $ok) {
            if (! $ok) {
                $out[] = "Extensão PHP {$ext} ausente.";
            }
        }
        if (empty($company['configured'])) {
            $out[] = 'Empresa fiscal não cadastrada.';
        } elseif (empty($company['complete'])) {
            $out[] = 'Cadastro da empresa incompleto: ' . implode(', ', $company['missing']);
        }
        if (empty($tax['validated'])) {
            $out[] = 'Parâmetros tributários sem validação do contador.';
        } elseif (($tax['missing_keys'] ?? []) !== []) {
            $out[] = 'Parâmetros tributários incompletos: ' . implode(', ', $tax['missing_keys']);
        }
        if (empty($cert['present'])) {
            $out[] = 'Certificado A1 ainda não instalado.';
        } elseif (empty($cert['compatible'])) {
            $out[] = (string) ($cert['message'] ?? 'Certificado incompatível ou ausente no disco.');
        }
        if (empty($accreditation['declared_by_admin'])) {
            $out[] = 'Credenciamento estadual ainda não declarado pelo administrador (campo informativo).';
        }
        if (! empty($environment['real_emission_authorized'])) {
            $out[] = 'Atenção: emissão real está autorizada nas configurações.';
        }
        if (! empty($environment['ml_xml_send_authorized'])) {
            $out[] = 'Atenção: envio de XML ao Mercado Livre está autorizado.';
        }
        foreach (['certificates', 'xml', 'danfe'] as $key) {
            if (empty($storage[$key]['ok'])) {
                $out[] = "Diretório fiscal {$key} inexistente ou sem escrita.";
            }
        }

        return $out;
    }
}

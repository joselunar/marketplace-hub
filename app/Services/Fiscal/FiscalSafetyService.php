<?php

namespace App\Services\Fiscal;

/**
 * Mantém emissão real e envio ML desligados nesta fase.
 */
class FiscalSafetyService
{
    public function __construct(
        private readonly ?\App\Services\Settings\SettingsService $settings = null,
    ) {
    }

    /**
     * Garante flags perigosas desligadas (idempotente).
     *
     * @return list<string> chaves forçadas para off
     */
    public function enforceHomologationLocks(?int $userId = null): array
    {
        $settings = $this->settings ?? service('settings');
        $forced = [];

        if ($settings->getBool('fiscal.real_emission_authorized', false)) {
            $settings->putMany(['fiscal.real_emission_authorized' => '0'], $userId);
            $forced[] = 'fiscal.real_emission_authorized';
        }
        if ($settings->getBool('fiscal.ml_xml_send_authorized', false)) {
            $settings->putMany(['fiscal.ml_xml_send_authorized' => '0'], $userId);
            $forced[] = 'fiscal.ml_xml_send_authorized';
        }
        if ($settings->get('fiscal.environment', FiscalGate::ENV_HOMOLOG) === FiscalGate::ENV_PROD
            && ! $settings->getBool('fiscal.real_emission_authorized', false)
        ) {
            // Ambiente configurado produção sem autorização: força homologação na config também.
            $settings->putMany(['fiscal.environment' => FiscalGate::ENV_HOMOLOG], $userId);
            $forced[] = 'fiscal.environment';
        }

        return $forced;
    }
}

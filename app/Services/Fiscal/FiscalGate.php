<?php

namespace App\Services\Fiscal;

use App\Exceptions\BusinessRuleException;
use App\Services\Settings\SettingsService;

/**
 * Portões de segurança do módulo fiscal.
 * Emissão real exige autorização explícita; por padrão só homologação.
 */
class FiscalGate
{
    public const ENV_HOMOLOG = 'homologacao';
    public const ENV_PROD    = 'producao';

    public function __construct(
        private readonly ?SettingsService $settings = null,
    ) {
    }

    public function moduleEnabled(): bool
    {
        return $this->settings()->getBool('fiscal.enabled', false);
    }

    public function realEmissionAuthorized(): bool
    {
        return $this->settings()->getBool('fiscal.real_emission_authorized', false);
    }

    public function mlSendAuthorized(): bool
    {
        return $this->settings()->getBool('fiscal.ml_xml_send_authorized', false);
    }

    public function configuredEnvironment(): string
    {
        $env = (string) $this->settings()->get('fiscal.environment', self::ENV_HOMOLOG);

        return $env === self::ENV_PROD ? self::ENV_PROD : self::ENV_HOMOLOG;
    }

    /**
     * Ambiente efetivo: produção só se flags explícitas permitirem.
     */
    public function effectiveEnvironment(): string
    {
        if (! $this->realEmissionAuthorized()) {
            return self::ENV_HOMOLOG;
        }

        return $this->configuredEnvironment();
    }

    public function tpAmb(): int
    {
        return $this->effectiveEnvironment() === self::ENV_PROD ? 1 : 2;
    }

    public function assertModuleEnabled(): void
    {
        if (! $this->moduleEnabled()) {
            throw new BusinessRuleException('Módulo fiscal desativado. Ative em Configurações → Fiscal (sem liberar emissão real).');
        }
    }

    public function assertCanTransmit(): void
    {
        $this->assertModuleEnabled();
        // Transmissão à SEFAZ é permitida em homologação; produção exige autorização.
        if ($this->effectiveEnvironment() === self::ENV_PROD && ! $this->realEmissionAuthorized()) {
            throw new BusinessRuleException('Emissão real bloqueada. Homologação SEFAZ permanece disponível até autorização.');
        }
    }

    public function assertCanSendToMercadoLivre(): void
    {
        $this->assertModuleEnabled();
        if ($this->effectiveEnvironment() !== self::ENV_PROD) {
            throw new BusinessRuleException('XML de homologação não pode ser enviado ao Mercado Livre.');
        }
        if (! $this->mlSendAuthorized() || ! $this->realEmissionAuthorized()) {
            throw new BusinessRuleException('Envio de XML ao Mercado Livre bloqueado até autorização de emissão real.');
        }
    }

    private function settings(): SettingsService
    {
        return $this->settings ?? service('settings');
    }
}

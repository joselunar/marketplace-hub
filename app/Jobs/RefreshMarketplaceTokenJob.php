<?php

namespace App\Jobs;

/**
 * Renova o token de uma conta se ele estiver perto de vencer. A renovação
 * trava a linha da conta, então um job duplicado só reaproveita o token novo.
 */
class RefreshMarketplaceTokenJob extends AbstractJob
{
    public const TYPE = 'marketplace_token';

    public static function label(): string
    {
        return 'Renovação de token';
    }

    public function handle(array $payload): string
    {
        $accountId = $this->id($payload, 'account_id');
        $adapter   = service('marketplaces')->forAccount($accountId);
        $account   = $adapter->refreshToken($accountId);

        return sprintf(
            '%s, conta %s: token válido até %s.',
            $adapter->name(),
            $account['name'],
            date('d/m/Y H:i', strtotime((string) $account['token_expires_at'])),
        );
    }
}

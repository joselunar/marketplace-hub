<?php

namespace App\Jobs;

/**
 * Renova o token de uma conta se ele estiver perto de vencer. A renovação
 * trava a linha da conta, então um job duplicado só reaproveita o token novo.
 */
class RefreshMercadoLivreTokenJob extends AbstractJob
{
    public const TYPE = 'meli_token';

    public static function label(): string
    {
        return 'Renovação de token';
    }

    public function handle(array $payload): string
    {
        $account = service('mercadoLivre')->auth()->refresh($this->id($payload, 'account_id'));

        return sprintf('Conta %s: token válido até %s.', $account['name'], date('d/m/Y H:i', strtotime((string) $account['token_expires_at'])));
    }
}

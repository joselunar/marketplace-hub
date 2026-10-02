<?php

namespace App\Jobs;

/**
 * Rede de segurança para notificações perdidas: importa os pedidos
 * atualizados nos últimos dias de uma conta.
 */
class ImportMarketplaceOrdersJob extends AbstractJob
{
    public const TYPE = 'marketplace_orders';

    public static function label(): string
    {
        return 'Importação de pedidos';
    }

    public function handle(array $payload): string
    {
        $accountId = $this->id($payload, 'account_id');
        $days      = isset($payload['days']) && is_int($payload['days']) ? max(1, min(30, $payload['days'])) : 2;
        $adapter   = service('marketplaces')->forAccount($accountId);
        $stats     = $adapter->importRecentOrders($accountId, $days);

        $message = sprintf('%s: %d novo(s), %d atualizado(s), %d sem mudança, %d com erro.', $adapter->name(), $stats['created'], $stats['updated'], $stats['unchanged'], $stats['failed']);
        if ($stats['errors'] !== []) {
            $message .= ' ' . implode(' | ', array_slice($stats['errors'], 0, 3));
        }

        return $message;
    }
}

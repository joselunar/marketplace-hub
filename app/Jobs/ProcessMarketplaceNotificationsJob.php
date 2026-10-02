<?php

namespace App\Jobs;

class ProcessMarketplaceNotificationsJob extends AbstractJob
{
    public const TYPE = 'marketplace_notifications';

    private const LIMIT = 200;

    public static function label(): string
    {
        return 'Notificações do marketplace';
    }

    public function handle(array $payload): string
    {
        $adapter = service('marketplaces')->get($this->slug($payload, 'marketplace'));
        $stats   = $adapter->processNotifications(self::LIMIT);

        return sprintf('%s: %d processada(s), %d com falha, %d voltaram para a fila.', $adapter->name(), $stats['processed'], $stats['failed'], $stats['retry']);
    }
}

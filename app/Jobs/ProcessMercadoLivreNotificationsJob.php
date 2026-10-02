<?php

namespace App\Jobs;

class ProcessMercadoLivreNotificationsJob extends AbstractJob
{
    public const TYPE = 'meli_notifications';

    private const LIMIT = 200;

    public static function label(): string
    {
        return 'Notificações do Mercado Livre';
    }

    public function handle(array $payload): string
    {
        $stats = service('mercadoLivreNotifications')->processPending(self::LIMIT);

        return sprintf('%d processada(s), %d com falha, %d voltaram para a fila.', $stats['processed'], $stats['failed'], $stats['retry']);
    }
}

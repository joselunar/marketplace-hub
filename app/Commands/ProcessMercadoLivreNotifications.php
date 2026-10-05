<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Processa a fila de notificações do Mercado Livre. Agendar a cada minuto:
 * o webhook só grava a notificação, quem consulta o pedido é este comando.
 */
class ProcessMercadoLivreNotifications extends BaseCommand
{
    protected $group       = 'Vitrine Hub';
    protected $name        = 'meli:process-notifications';
    protected $description = 'Processa as notificações do Mercado Livre que estão na fila.';
    protected $usage       = 'meli:process-notifications [--limit <n>]';
    protected $options     = [
        '--limit' => 'Máximo de notificações nesta execução (padrão 100, máximo 500).',
    ];

    public function run(array $params)
    {
        $limit = $params['limit'] ?? CLI::getOption('limit') ?? '100';
        if (! ctype_digit((string) $limit) || (int) $limit < 1) {
            CLI::error('Informe um limite válido.');

            return EXIT_USER_INPUT;
        }

        $stats = service('mercadoLivreNotifications')->processPending((int) $limit);

        CLI::write(
            sprintf('%d processada(s), %d com falha, %d voltaram para a fila.', $stats['processed'], $stats['failed'], $stats['retry']),
            $stats['failed'] > 0 ? 'yellow' : 'green',
        );

        return $stats['failed'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}

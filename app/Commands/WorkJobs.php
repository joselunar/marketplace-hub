<?php

namespace App\Commands;

use App\Models\JobModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Processa a fila de jobs. Termina quando a fila esvazia ou ao atingir os
 * limites, para caber no intervalo de um minuto do cron.
 */
class WorkJobs extends BaseCommand
{
    protected $group       = 'Vitrine Hub';
    protected $name        = 'jobs:work';
    protected $description = 'Executa os jobs pendentes da fila (com novas tentativas para falhas temporárias).';
    protected $usage       = 'jobs:work [--max-jobs <n>] [--max-time <segundos>]';
    protected $options     = [
        '--max-jobs' => 'Máximo de jobs nesta execução (padrão em Config\Automation).',
        '--max-time' => 'Tempo máximo em segundos (padrão em Config\Automation).',
    ];

    public function run(array $params)
    {
        $maxJobs = $params['max-jobs'] ?? CLI::getOption('max-jobs');
        $maxTime = $params['max-time'] ?? CLI::getOption('max-time');

        foreach (['max-jobs' => $maxJobs, 'max-time' => $maxTime] as $option => $value) {
            if ($value !== null && (! ctype_digit((string) $value) || (int) $value < 1)) {
                CLI::error(sprintf('Informe um número válido em --%s.', $option));

                return EXIT_USER_INPUT;
            }
        }

        $queue = service('jobQueue');
        $types = $queue->types();
        $stats = $queue->work(
            $maxJobs !== null ? (int) $maxJobs : null,
            $maxTime !== null ? (int) $maxTime : null,
            static function (array $job, string $status, string $message) use ($types): void {
                CLI::write(
                    sprintf('#%d %s%s: %s', $job['id'], $types[$job['type']] ?? $job['type'], $job['reference'] ? ' (' . $job['reference'] . ')' : '', $message),
                    match ($status) {
                        JobModel::STATUS_COMPLETED => 'green',
                        JobModel::STATUS_PENDING   => 'yellow',
                        default                    => 'red',
                    },
                );
            },
        );

        if ($stats['released'] > 0) {
            CLI::write(sprintf('%d job(s) interrompido(s) foram liberados.', $stats['released']), 'yellow');
        }

        CLI::write(
            sprintf('%d job(s): %d concluído(s), %d reagendado(s), %d com falha.', $stats['processed'], $stats['completed'], $stats['retried'], $stats['failed']),
            $stats['failed'] > 0 ? 'yellow' : 'green',
        );

        return $stats['failed'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}

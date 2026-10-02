<?php

namespace App\Commands;

use App\Models\SyncLogModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Enfileira as tarefas vencidas. Agendar no cron a cada minuto, junto com o worker:
 *     * * * * * cd /caminho/do/projeto && php spark schedule:run && php spark jobs:work
 */
class RunSchedule extends BaseCommand
{
    protected $group       = 'Marketplace Hub';
    protected $name        = 'schedule:run';
    protected $description = 'Enfileira os jobs das tarefas agendadas que estão vencidas.';
    protected $usage       = 'schedule:run';

    public function run(array $params)
    {
        $results = service('scheduler')->runDue();

        if ($results === []) {
            CLI::write('Nenhuma tarefa vencida.', 'green');

            return EXIT_SUCCESS;
        }

        $errors = 0;
        foreach ($results as $task => $result) {
            $failed = $result['status'] === SyncLogModel::STATUS_ERROR;
            $errors += $failed ? 1 : 0;
            CLI::write(sprintf('%s: %s', $task, $result['message']), $failed ? 'red' : 'green');
        }

        return $errors > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}

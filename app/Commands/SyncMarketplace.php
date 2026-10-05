<?php

namespace App\Commands;

use App\Exceptions\BusinessRuleException;
use App\Models\JobModel;
use App\Models\SyncLogModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Sincronização completa com os marketplaces sob demanda: enfileira as
 * tarefas de todos os adaptadores, ignorando os intervalos, e processa a fila.
 */
class SyncMarketplace extends BaseCommand
{
    protected $group       = 'Vitrine Hub';
    protected $name        = 'marketplace:sync';
    protected $description = 'Enfileira agora tokens, notificações, pedidos, estoque e preço dos anúncios e processa a fila.';
    protected $usage       = 'marketplace:sync [--no-work]';
    protected $options     = [
        '--no-work' => 'Só enfileira; o worker do cron executa depois.',
    ];

    /**
     * Estoque e preço só entram com a atualização automática correspondente ligada.
     */
    private const TASKS = ['marketplace_tokens', 'marketplace_notifications', 'marketplace_orders', 'listing_stock', 'listing_prices'];

    public function run(array $params)
    {
        $scheduler = service('scheduler');

        foreach (self::TASKS as $task) {
            try {
                $result = $scheduler->runNow($task, null, JobModel::SOURCE_COMMAND);
                CLI::write(sprintf('%s: %s', $task, $result['message']), $result['status'] === SyncLogModel::STATUS_SUCCESS ? 'green' : 'yellow');
            } catch (BusinessRuleException $e) {
                CLI::write(sprintf('%s: %s', $task, $e->getMessage()), 'yellow');
            }
        }

        if (array_key_exists('no-work', $params) || CLI::getOption('no-work') !== null) {
            return EXIT_SUCCESS;
        }

        return $this->call('jobs:work') === EXIT_SUCCESS ? EXIT_SUCCESS : EXIT_ERROR;
    }
}

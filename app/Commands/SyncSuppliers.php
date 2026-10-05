<?php

namespace App\Commands;

use App\Jobs\SyncSupplierPricesJob;
use App\Jobs\SyncSupplierStockJob;
use App\Models\JobModel;
use App\Services\Suppliers\SupplierSyncService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Sincroniza estoque e/ou custos dos fornecedores com adapter de integração.
 */
class SyncSuppliers extends BaseCommand
{
    protected $group       = 'Vitrine Hub';
    protected $name        = 'suppliers:sync';
    protected $description = 'Sincroniza estoque e custos dos fornecedores com adapter de integração.';
    protected $usage       = 'suppliers:sync [--supplier <id>] [--only stock|prices] [--queue]';
    protected $options     = [
        '--supplier' => 'Sincroniza somente o fornecedor informado.',
        '--only'     => 'stock (só estoque) ou prices (só custos). Sem a opção, os dois.',
        '--queue'    => 'Só enfileira os jobs (com novas tentativas) para o worker executar.',
    ];

    private const JOB_TYPES = [
        SupplierSyncService::MODE_STOCK  => SyncSupplierStockJob::TYPE,
        SupplierSyncService::MODE_PRICES => SyncSupplierPricesJob::TYPE,
    ];

    public function run(array $params)
    {
        $supplierId = $params['supplier'] ?? CLI::getOption('supplier');
        $only       = $params['only'] ?? CLI::getOption('only');
        $queue      = array_key_exists('queue', $params) || CLI::getOption('queue') !== null;

        if ($supplierId !== null && ! ctype_digit((string) $supplierId)) {
            CLI::error('Informe um ID de fornecedor válido.');

            return EXIT_USER_INPUT;
        }
        if ($only !== null && ! isset(self::JOB_TYPES[$only])) {
            CLI::error('Use --only stock ou --only prices.');

            return EXIT_USER_INPUT;
        }

        $sync      = service('supplierSync');
        $modes     = $only !== null ? [$only] : array_keys(self::JOB_TYPES);
        $syncable  = $sync->syncable();
        $suppliers = $supplierId !== null
            ? array_values(array_filter($syncable, static fn (array $s): bool => $s['id'] === (int) $supplierId))
            : $syncable;

        if ($suppliers === []) {
            CLI::write($supplierId !== null
                ? 'O fornecedor informado não está ativo ou não tem adapter de integração registrado.'
                : 'Nenhum fornecedor ativo com adapter de integração. Fornecedores manuais e por CSV são atualizados pela importação de arquivo.', 'yellow');

            return $supplierId !== null ? EXIT_ERROR : EXIT_SUCCESS;
        }

        $failures = 0;
        foreach ($suppliers as $supplier) {
            foreach ($modes as $mode) {
                if (! $supplier[$mode]) {
                    continue;
                }

                if ($queue) {
                    $id = service('jobQueue')->enqueue(self::JOB_TYPES[$mode], ['supplier_id' => $supplier['id']], JobModel::SOURCE_COMMAND, $supplier['name']);
                    CLI::write(sprintf('%s (%s): %s', $supplier['name'], $mode, $id === null ? 'já estava na fila.' : 'job #' . $id . ' enfileirado.'), 'green');

                    continue;
                }

                try {
                    $stats = $sync->sync($supplier['id'], $mode);
                    CLI::write(sprintf('%s (%s): %d atualizada(s), %d SKU(s) desconhecido(s), %d erro(s), %d troca(s) de fornecedor.', $supplier['name'], $mode, $stats['updated'], $stats['skipped'], $stats['errors'], $stats['changed']), $stats['errors'] > 0 ? 'yellow' : 'green');
                } catch (Throwable $e) {
                    $failures++;
                    CLI::error(sprintf('%s (%s): %s', $supplier['name'], $mode, $e->getMessage()));
                }
            }
        }

        return $failures > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}

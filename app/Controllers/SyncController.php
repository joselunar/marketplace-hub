<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\JobModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Fila de jobs, tarefas agendadas e situação do cron.
 */
class SyncController extends BaseController
{
    private const HOME = 'sync';

    public function index(): string
    {
        $queue   = service('jobQueue');
        $filters = $this->filters(['status', 'type', 'q']);
        $model   = new JobModel();
        $jobs    = $model->withUser()
            ->applyFilters($filters)
            ->orderBy('jobs.id', 'DESC')
            ->paginate($this->perPage());
        $settings = service('settings');

        return view('sync/index', [
            'title'       => 'Sincronizações',
            'jobs'        => $jobs,
            'pager'       => $model->pager,
            'filters'     => $filters,
            'counts'      => $queue->counts(),
            'types'       => $queue->types(),
            'heartbeat'   => $queue->heartbeat(),
            'tasks'       => service('scheduler')->overview(),
            'suppliers'   => service('supplierSync')->syncable(),
            'autoStock'   => $settings->getBool('sync.auto_update_stock'),
            'autoPrice'   => $settings->getBool('sync.auto_update_price'),
            'config'      => $queue->config(),
            'breadcrumbs' => ['Automação' => null, 'Sincronizações' => null],
        ]);
    }

    public function runTask(string $name): RedirectResponse
    {
        try {
            $result = service('scheduler')->runNow($name, (int) $this->auth()->id());
        } catch (BusinessRuleException $e) {
            return redirect()->to(self::HOME)->with('error', $e->getMessage());
        }

        return redirect()->to(self::HOME)->with('success', $result['message'] . ' O worker do cron executa em até um minuto.');
    }

    public function retry(int $id): RedirectResponse
    {
        return $this->attempt(static fn () => service('jobQueue')->retry($id), 'Job devolvido à fila.');
    }

    public function cancel(int $id): RedirectResponse
    {
        return $this->attempt(static fn () => service('jobQueue')->cancel($id), 'Job cancelado.');
    }

    public function retryFailed(): RedirectResponse
    {
        $count = service('jobQueue')->retryAllFailed();

        return redirect()->to(self::HOME)->with($count > 0 ? 'success' : 'info', $count > 0 ? sprintf('%d job(s) devolvido(s) à fila.', $count) : 'Nenhum job com falha para executar de novo.');
    }

    public function cancelPending(): RedirectResponse
    {
        $count = service('jobQueue')->cancelAllPending();

        return redirect()->to(self::HOME)->with($count > 0 ? 'success' : 'info', $count > 0 ? sprintf('%d job(s) cancelado(s).', $count) : 'Nenhum job aguardando na fila.');
    }

    private function attempt(callable $operation, string $success): RedirectResponse
    {
        try {
            $operation();
        } catch (BusinessRuleException $e) {
            return redirect()->to(self::HOME)->with('error', $e->getMessage());
        }

        return redirect()->to(self::HOME)->with('success', $success);
    }
}

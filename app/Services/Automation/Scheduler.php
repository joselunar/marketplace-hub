<?php

namespace App\Services\Automation;

use App\Exceptions\BusinessRuleException;
use App\Jobs\ImportMarketplaceOrdersJob;
use App\Jobs\ProcessMarketplaceNotificationsJob;
use App\Jobs\RecalculateSupplierSelectionJob;
use App\Jobs\RefreshMarketplaceTokenJob;
use App\Jobs\SyncSupplierPricesJob;
use App\Jobs\SyncSupplierStockJob;
use App\Models\JobModel;
use App\Models\MarketplaceAccountModel;
use App\Models\ScheduledTaskModel;
use App\Models\SyncLogModel;
use App\Services\Marketplaces\Contracts\MarketplaceAdapter;
use App\Services\Marketplaces\MarketplaceAccountService;
use App\Services\Marketplaces\MarketplaceRegistry;
use App\Services\Settings\SettingsService;
use App\Services\Suppliers\SupplierSyncService;
use Config\Automation;
use Throwable;

/**
 * Agendador: a cada execução (cron de minuto) enfileira os jobs das tarefas
 * vencidas. Não executa nada pesado; quem processa é o worker (jobs:work).
 */
class Scheduler
{
    /**
     * Folga para o cron que dispara alguns segundos antes do minuto cheio.
     */
    private const TOLERANCE_SECONDS = 20;

    private const ORDERS_DAYS = 2;

    public function __construct(
        private readonly ?JobQueue $queue = null,
        private readonly ScheduledTaskModel $tasks = new ScheduledTaskModel(),
        private readonly ?SettingsService $settings = null,
        private readonly ?SupplierSyncService $supplierSync = null,
        private readonly ?ListingAutoSync $autoSync = null,
        private readonly Automation $config = new Automation(),
        private readonly ?MarketplaceRegistry $marketplaces = null,
    ) {
    }

    /**
     * Enfileira as tarefas vencidas.
     *
     * @return array<string, array{status: string, message: string}> só as tarefas executadas
     */
    public function runDue(?int $now = null): array
    {
        $now ??= time();
        $last    = $this->tasks->allByName();
        $results = [];

        foreach (array_keys($this->config->tasks) as $name) {
            $state = $this->state($name, $last[$name] ?? null, $now);
            if (! $state['due']) {
                continue;
            }

            $results[$name] = $this->execute($name, JobModel::SOURCE_SCHEDULE, null, $now);
        }

        $purged = $this->queue()->purgeFinished();

        $this->tasks->record(
            ScheduledTaskModel::SCHEDULER,
            in_array(SyncLogModel::STATUS_ERROR, array_column($results, 'status'), true) ? SyncLogModel::STATUS_WARNING : SyncLogModel::STATUS_SUCCESS,
            sprintf('%d tarefa(s) executada(s)%s.', count($results), $purged > 0 ? sprintf(', %d job(s) antigo(s) removido(s)', $purged) : ''),
            date('Y-m-d H:i:s', $now),
        );

        return $results;
    }

    /**
     * Executa uma tarefa agora, pelo painel ou por comando.
     *
     * @return array{status: string, message: string}
     */
    public function runNow(string $name, ?int $userId = null, string $source = JobModel::SOURCE_MANUAL): array
    {
        if (! isset($this->config->tasks[$name])) {
            throw new BusinessRuleException('Tarefa desconhecida.');
        }

        $requires = $this->config->tasks[$name]['requires'] ?? null;
        if ($requires !== null && ! $this->settings()->getBool($requires)) {
            throw new BusinessRuleException('Ligue a opção correspondente em Configurações > Sincronização para usar esta tarefa.');
        }

        $result = $this->execute($name, $source, $userId, time());
        if ($result['status'] === SyncLogModel::STATUS_ERROR) {
            throw new BusinessRuleException($result['message']);
        }

        return $result;
    }

    /**
     * Tarefas com intervalo, situação e próxima execução, para a tela.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(?int $now = null): array
    {
        $now ??= time();
        $last = $this->tasks->allByName();
        $list = [];

        foreach ($this->config->tasks as $name => $task) {
            $row    = $last[$name] ?? null;
            $state  = $this->state($name, $row, $now);
            $list[] = [
                'name'         => $name,
                'label'        => $task['label'],
                'interval'     => $state['interval'],
                'enabled'      => $state['enabled'],
                'disabled_why' => $state['disabled_why'],
                'last_run_at'  => $row['last_run_at'] ?? null,
                'last_status'  => $row['last_status'] ?? null,
                'last_message' => $row['last_message'] ?? null,
                'next_run_at'  => $state['enabled'] ? ($row === null ? $now : max($now, strtotime((string) $row['last_run_at']) + $state['interval'] * 60)) : null,
            ];
        }

        return $list;
    }

    /**
     * @return array{interval: int, enabled: bool, disabled_why: string|null, due: bool}
     */
    private function state(string $name, ?array $row, int $now): array
    {
        $task     = $this->config->tasks[$name];
        $interval = isset($task['setting']) ? max(0, $this->settings()->getInt($task['setting'])) : (int) ($task['interval'] ?? 0);
        $requires = $task['requires'] ?? null;

        $why = match (true) {
            $interval === 0 => 'Intervalo zerado nas configurações.',
            $requires !== null && ! $this->settings()->getBool($requires) => 'Atualização automática desligada nas configurações.',
            default => null,
        };

        $due = $why === null
            && ($row === null || $row['last_run_at'] === null || strtotime((string) $row['last_run_at']) + $interval * 60 - self::TOLERANCE_SECONDS <= $now);

        return ['interval' => $interval, 'enabled' => $why === null, 'disabled_why' => $why, 'due' => $due];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function execute(string $name, string $source, ?int $userId, int $now): array
    {
        try {
            $message = $this->dispatch($name, $source, $userId);
            $status  = SyncLogModel::STATUS_SUCCESS;
        } catch (Throwable $e) {
            log_message('error', 'Tarefa agendada {task} falhou: {message}', ['task' => $name, 'message' => $e::class . ': ' . $e->getMessage()]);
            $message = $e instanceof BusinessRuleException ? $e->getMessage() : 'Erro inesperado: ' . $e->getMessage();
            $status  = SyncLogModel::STATUS_ERROR;
        }

        $this->tasks->record($name, $status, $message, date('Y-m-d H:i:s', $now));

        return ['status' => $status, 'message' => $message];
    }

    private function dispatch(string $name, string $source, ?int $userId): string
    {
        return match ($name) {
            'marketplace_notifications' => $this->notifications($source, $userId),
            'marketplace_tokens'        => $this->tokens($source, $userId),
            'supplier_selection'        => $this->summary($this->queue()->enqueue(RecalculateSupplierSelectionJob::TYPE, [], $source, null, $userId) !== null ? 1 : 0),
            'supplier_stock'            => $this->suppliers(SyncSupplierStockJob::TYPE, SupplierSyncService::MODE_STOCK, $source, $userId),
            'supplier_prices'           => $this->suppliers(SyncSupplierPricesJob::TYPE, SupplierSyncService::MODE_PRICES, $source, $userId),
            'listing_stock'             => $this->listings($this->autoSync()->enqueueStock($source, $userId), 'Nenhum anúncio com estoque diferente do fornecedor.'),
            'listing_prices'            => $this->listings($this->autoSync()->enqueuePrices($source, $userId), 'Nenhum anúncio com preço para conferir.'),
            'marketplace_orders'        => $this->orders($source, $userId),
            default                     => throw new BusinessRuleException('Tarefa sem implementação: ' . $name),
        };
    }

    /**
     * Só enfileira quando há notificação esperando, para não encher a fila a cada minuto.
     */
    private function notifications(string $source, ?int $userId): string
    {
        $count   = 0;
        $pending = 0;

        foreach ($this->marketplaces()->supporting(MarketplaceAdapter::CAPABILITY_NOTIFICATIONS) as $slug => $adapter) {
            $waiting  = $adapter->pendingNotifications();
            $pending += $waiting;
            if ($waiting === 0 && $source === JobModel::SOURCE_SCHEDULE) {
                continue;
            }

            $reference = sprintf('%s: %d pendente(s)', $adapter->name(), $waiting);
            $count += $this->queue()->enqueue(ProcessMarketplaceNotificationsJob::TYPE, ['marketplace' => $slug], $source, $reference, $userId) !== null ? 1 : 0;
        }

        return $pending === 0 && $source === JobModel::SOURCE_SCHEDULE ? 'Nenhuma notificação na fila.' : $this->summary($count);
    }

    /**
     * Cada marketplace renova com a antecedência que a sua documentação pede.
     */
    private function tokens(string $source, ?int $userId): string
    {
        $count      = 0;
        $configured = 0;

        foreach ($this->marketplaces()->supporting(MarketplaceAdapter::CAPABILITY_TOKEN_REFRESH) as $slug => $adapter) {
            if (! $adapter->isConfigured()) {
                continue;
            }
            $configured++;

            $limit   = date('Y-m-d H:i:s', time() + $adapter->refreshBeforeSeconds());
            $records = (new MarketplaceAccountModel())->expiringBefore($limit)
                ->join('marketplaces', 'marketplaces.id = marketplace_accounts.marketplace_id')
                ->where('marketplaces.slug', $slug)
                ->select('marketplace_accounts.id, marketplace_accounts.name')
                ->findAll();

            foreach ($records as $account) {
                $reference = $adapter->name() . ': ' . $account['name'];
                $count += $this->queue()->enqueue(RefreshMarketplaceTokenJob::TYPE, ['account_id' => (int) $account['id']], $source, $reference, $userId) !== null ? 1 : 0;
            }
        }

        if ($configured === 0) {
            return 'Nenhuma integração de marketplace configurada.';
        }

        return $count === 0 ? 'Nenhum token perto de vencer.' : $this->summary($count);
    }

    private function suppliers(string $type, string $mode, string $source, ?int $userId): string
    {
        $count = 0;
        foreach ($this->supplierSync()->syncable() as $supplier) {
            if ($supplier[$mode]) {
                $count += $this->queue()->enqueue($type, ['supplier_id' => $supplier['id']], $source, $supplier['name'], $userId) !== null ? 1 : 0;
            }
        }

        return $count === 0 && $this->supplierSync()->syncableIds($mode) === []
            ? 'Nenhum fornecedor com adapter de integração para sincronizar.'
            : $this->summary($count);
    }

    private function orders(string $source, ?int $userId): string
    {
        $count    = 0;
        $accounts = 0;
        $service  = new MarketplaceAccountService();

        foreach ($this->marketplaces()->supporting(MarketplaceAdapter::CAPABILITY_ORDERS) as $slug => $adapter) {
            foreach ($service->connected($slug) as $account) {
                $accounts++;
                $payload   = ['account_id' => (int) $account['id'], 'days' => self::ORDERS_DAYS];
                $reference = $adapter->name() . ': ' . $account['name'];
                $count += $this->queue()->enqueue(ImportMarketplaceOrdersJob::TYPE, $payload, $source, $reference, $userId) !== null ? 1 : 0;
            }
        }

        return $accounts === 0 ? 'Nenhuma conta de marketplace conectada.' : $this->summary($count);
    }

    private function listings(int $count, string $empty): string
    {
        return $count === 0 ? $empty : $this->summary($count);
    }

    private function summary(int $count): string
    {
        return $count === 0 ? 'Nada novo: os jobs já estavam na fila.' : sprintf('%d job(s) enfileirado(s).', $count);
    }

    private function queue(): JobQueue
    {
        return $this->queue ?? service('jobQueue');
    }

    private function settings(): SettingsService
    {
        return $this->settings ?? service('settings');
    }

    private function supplierSync(): SupplierSyncService
    {
        return $this->supplierSync ?? service('supplierSync');
    }

    private function autoSync(): ListingAutoSync
    {
        return $this->autoSync ?? service('listingAutoSync');
    }

    private function marketplaces(): MarketplaceRegistry
    {
        return $this->marketplaces ?? service('marketplaces');
    }
}

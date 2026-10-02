<?php

namespace App\Services\Automation;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Jobs\JobInterface;
use App\Models\JobModel;
use App\Models\ScheduledTaskModel;
use App\Models\SyncLogModel;
use App\Services\Http\TransportException;
use App\Services\Marketplaces\Contracts\MarketplaceApiError;
use App\Services\Suppliers\Connectors\UnsupportedOperationException;
use CodeIgniter\Database\BaseConnection;
use Config\Automation;
use InvalidArgumentException;
use JsonException;
use ReflectionClass;
use Throwable;

/**
 * Fila de jobs em banco de dados.
 *
 * - Um job igual (mesmo tipo e payload) não entra duas vezes na fila: a
 *   chave única vale enquanto ele espera e é liberada quando ele começa.
 * - A reserva é um UPDATE condicional, então vários workers podem rodar
 *   ao mesmo tempo sem pegar o mesmo job.
 * - Falhas temporárias (rede, 429, 5xx) voltam para a fila com espera
 *   crescente; regras de negócio e operações não suportadas falham na hora.
 * - A falha definitiva fica em sync_logs para aparecer em Logs e no dashboard.
 */
class JobQueue
{
    public const SYNC_INTEGRATION = 'automation';

    private const MAX_TEXT = 1000;

    private BaseConnection $db;

    public function __construct(
        private readonly JobModel $jobs = new JobModel(),
        private readonly ScheduledTaskModel $tasks = new ScheduledTaskModel(),
        private readonly SyncLogModel $syncLogs = new SyncLogModel(),
        private readonly Automation $config = new Automation(),
    ) {
        $this->db = $this->jobs->db;
    }

    public function config(): Automation
    {
        return $this->config;
    }

    /**
     * @return array<string, string> tipo => nome
     */
    public function types(): array
    {
        $types = [];
        foreach ($this->config->jobs as $type => $class) {
            $types[$type] = $class::label();
        }

        return $types;
    }

    /**
     * Coloca um job na fila.
     *
     * @return int|null ID do job, ou null se um job igual já estava esperando
     */
    public function enqueue(string $type, array $payload = [], string $source = JobModel::SOURCE_COMMAND, ?string $reference = null, ?int $userId = null, int $delaySeconds = 0): ?int
    {
        if (! isset($this->config->jobs[$type])) {
            throw new InvalidArgumentException('Tipo de job desconhecido: ' . $type);
        }

        ksort($payload);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_TEXT) {
            throw new InvalidArgumentException('Payload do job grande demais.');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->query(
            'INSERT INTO jobs (type, payload, unique_key, reference, status, attempts, max_attempts, available_at, source, created_by, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
            [
                $type,
                $encoded,
                $this->uniqueKey($type, $encoded),
                $reference === null ? null : mb_substr($reference, 0, 120),
                JobModel::STATUS_PENDING,
                $this->config->maxAttempts,
                date('Y-m-d H:i:s', time() + max(0, $delaySeconds)),
                $source,
                $userId,
                $now,
                $now,
            ],
        );

        return $this->db->affectedRows() === 1 ? (int) $this->db->insertID() : null;
    }

    /**
     * Executa jobs até esvaziar a fila ou atingir os limites.
     *
     * @param callable(array, string, string): void|null $onJob recebe o job, o status final e a mensagem
     *
     * @return array{processed: int, completed: int, retried: int, failed: int, released: int}
     */
    public function work(?int $maxJobs = null, ?int $maxSeconds = null, ?callable $onJob = null): array
    {
        $maxJobs ??= $this->config->workerMaxJobs;
        $maxSeconds ??= $this->config->workerMaxSeconds;
        $deadline = microtime(true) + max(1, $maxSeconds);

        $stats = ['processed' => 0, 'completed' => 0, 'retried' => 0, 'failed' => 0, 'released' => $this->releaseStale()];

        while ($stats['processed'] < $maxJobs && microtime(true) < $deadline) {
            $job = $this->claimNext();
            if ($job === null) {
                break;
            }

            [$status, $message] = $this->run($job);
            $stats['processed']++;
            $stats[match ($status) {
                JobModel::STATUS_COMPLETED => 'completed',
                JobModel::STATUS_PENDING   => 'retried',
                default                    => 'failed',
            }]++;

            if ($onJob !== null) {
                $onJob($job, $status, $message);
            }
        }

        $this->tasks->record(
            ScheduledTaskModel::WORKER,
            $stats['failed'] > 0 ? SyncLogModel::STATUS_WARNING : SyncLogModel::STATUS_SUCCESS,
            sprintf('%d job(s): %d concluído(s), %d reagendado(s), %d com falha.', $stats['processed'], $stats['completed'], $stats['retried'], $stats['failed']),
        );

        return $stats;
    }

    /**
     * Reserva o próximo job disponível.
     */
    public function claimNext(): ?array
    {
        $now        = date('Y-m-d H:i:s');
        $candidates = $this->jobs->builder()
            ->select('id')
            ->where('status', JobModel::STATUS_PENDING)
            ->where('available_at <=', $now)
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit(10)
            ->get()
            ->getResultArray();

        foreach (array_column($candidates, 'id') as $id) {
            $this->jobs->builder()
                ->where('id', (int) $id)
                ->where('status', JobModel::STATUS_PENDING)
                ->set('attempts', 'attempts + 1', false)
                ->set(['status' => JobModel::STATUS_RUNNING, 'unique_key' => null, 'started_at' => $now, 'finished_at' => null, 'updated_at' => $now])
                ->update();

            if ($this->db->affectedRows() === 1) {
                return $this->jobs->find((int) $id);
            }
        }

        return null;
    }

    /**
     * Executa um job já reservado.
     *
     * @return array{0: string, 1: string} status final e mensagem
     */
    public function run(array $job): array
    {
        $id = (int) $job['id'];

        try {
            $class = $this->config->jobs[$job['type']] ?? throw new InvalidArgumentException('Tipo de job desconhecido: ' . $job['type']);
            /** @var JobInterface $handler */
            $handler = new $class();
            $payload = json_decode((string) $job['payload'], true, 16, JSON_THROW_ON_ERROR);
            $result  = $handler->handle(is_array($payload) ? $payload : []);
        } catch (Throwable $e) {
            return $this->failed($job, $e);
        }

        $this->jobs->update($id, [
            'status'      => JobModel::STATUS_COMPLETED,
            'finished_at' => date('Y-m-d H:i:s'),
            'result'      => mb_substr($result, 0, self::MAX_TEXT),
            'last_error'  => null,
        ]);

        return [JobModel::STATUS_COMPLETED, $result];
    }

    /**
     * Devolve à fila um job que falhou.
     */
    public function retry(int $id): void
    {
        $job = $this->jobs->find($id) ?? throw new BusinessRuleException('Job não encontrado.');

        if ($job['status'] !== JobModel::STATUS_FAILED) {
            throw new BusinessRuleException('Só jobs com falha podem ser executados novamente.');
        }

        if (! $this->requeue($job, 0, 0)) {
            throw new BusinessRuleException('Já existe um job igual aguardando na fila.');
        }
    }

    /**
     * @return int quantos voltaram para a fila
     */
    public function retryAllFailed(): int
    {
        $count = 0;
        foreach ($this->jobs->where('status', JobModel::STATUS_FAILED)->orderBy('id')->findAll(500) as $job) {
            if ($this->requeue($job, 0, 0)) {
                $count++;
            } else {
                // Já há um igual esperando: este não precisa ficar como falha pendente.
                $this->jobs->update((int) $job['id'], ['status' => JobModel::STATUS_CANCELLED, 'finished_at' => date('Y-m-d H:i:s')]);
            }
        }

        return $count;
    }

    public function cancel(int $id): void
    {
        $this->jobs->builder()
            ->where('id', $id)
            ->where('status', JobModel::STATUS_PENDING)
            ->set(['status' => JobModel::STATUS_CANCELLED, 'unique_key' => null, 'finished_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')])
            ->update();

        if ($this->db->affectedRows() !== 1) {
            throw new BusinessRuleException('Só jobs aguardando na fila podem ser cancelados.');
        }
    }

    public function cancelAllPending(): int
    {
        $now = date('Y-m-d H:i:s');
        $this->jobs->builder()
            ->where('status', JobModel::STATUS_PENDING)
            ->set(['status' => JobModel::STATUS_CANCELLED, 'unique_key' => null, 'finished_at' => $now, 'updated_at' => $now])
            ->update();

        return $this->db->affectedRows();
    }

    /**
     * Jobs presos em "executando" (processo interrompido) voltam para a fila
     * ou falham se já gastaram todas as tentativas.
     */
    public function releaseStale(): int
    {
        $limit    = date('Y-m-d H:i:s', time() - $this->config->staleSeconds);
        $released = 0;
        $stale    = $this->jobs->where('status', JobModel::STATUS_RUNNING)->where('started_at <', $limit)->findAll(100);

        foreach ($stale as $job) {
            $message = 'Execução interrompida antes de terminar (worker parado ou tempo esgotado).';

            if ((int) $job['attempts'] < (int) $job['max_attempts'] && $this->requeue($job, (int) $job['attempts'], 0, $message)) {
                $released++;

                continue;
            }

            $this->markFailed($job, $message);
            $released++;
        }

        return $released;
    }

    /**
     * Apaga jobs concluídos e cancelados antigos.
     */
    public function purgeFinished(): int
    {
        $this->jobs->builder()
            ->whereIn('status', [JobModel::STATUS_COMPLETED, JobModel::STATUS_CANCELLED])
            ->where('updated_at <', date('Y-m-d H:i:s', time() - $this->config->keepFinishedDays * 86400))
            ->delete();

        return $this->db->affectedRows();
    }

    /**
     * Segundos de espera antes da tentativa seguinte à de número $attempt.
     */
    public function backoffSeconds(int $attempt): int
    {
        $exponent = max(0, min(20, $attempt - 1));

        return min($this->config->retryMaxSeconds, $this->config->retryBaseSeconds * (2 ** $exponent));
    }

    /**
     * Falhas temporárias valem nova tentativa; erros de regra ou de dados, não.
     */
    public function isRetryable(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof MarketplaceApiError) {
                return $current->isTemporary();
            }
            if ($current instanceof TransportException) {
                return true;
            }
            if ($current instanceof UnsupportedOperationException) {
                return false;
            }
        }

        return ! ($e instanceof BusinessRuleException
            || $e instanceof ValidationException
            || $e instanceof InvalidArgumentException
            || $e instanceof JsonException);
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->jobs->countsByStatus() + array_fill_keys(array_keys(JobModel::STATUS_LABELS), 0);
    }

    /**
     * Situação do agendador e do worker para a tela de sincronizações e o dashboard.
     *
     * @return array{scheduler: array|null, worker: array|null, scheduler_late: bool}
     */
    public function heartbeat(): array
    {
        $rows      = $this->tasks->allByName();
        $scheduler = $rows[ScheduledTaskModel::SCHEDULER] ?? null;
        $limit     = time() - $this->config->schedulerAlertMinutes * 60;

        return [
            'scheduler'      => $scheduler,
            'worker'         => $rows[ScheduledTaskModel::WORKER] ?? null,
            'scheduler_late' => $scheduler === null || strtotime((string) $scheduler['last_run_at']) < $limit,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function failed(array $job, Throwable $e): array
    {
        $message = $this->messageFor($e);

        log_message($this->isRetryable($e) ? 'warning' : 'error', 'Job {id} ({type}) falhou na tentativa {attempt}: {message}', [
            'id'      => $job['id'],
            'type'    => $job['type'],
            'attempt' => $job['attempts'],
            'message' => $e::class . ': ' . $e->getMessage(),
        ]);

        $attempts = (int) $job['attempts'];
        if ($this->isRetryable($e) && $attempts < (int) $job['max_attempts']) {
            $delay = $this->backoffSeconds($attempts);
            if ($this->requeue($job, $attempts, $delay, $message)) {
                return [JobModel::STATUS_PENDING, sprintf('%s Nova tentativa em %s.', $message, $this->duration($delay))];
            }

            $this->jobs->update((int) $job['id'], [
                'status'      => JobModel::STATUS_CANCELLED,
                'finished_at' => date('Y-m-d H:i:s'),
                'last_error'  => mb_substr($message . ' Um job igual já está na fila.', 0, self::MAX_TEXT),
            ]);

            return [JobModel::STATUS_CANCELLED, $message];
        }

        $this->markFailed($job, $message);

        return [JobModel::STATUS_FAILED, $message];
    }

    /**
     * Volta o job para "aguardando" com a chave única restaurada.
     * Retorna false quando um job igual já está esperando.
     */
    private function requeue(array $job, int $attempts, int $delaySeconds, ?string $error = null): bool
    {
        $now = date('Y-m-d H:i:s');
        $this->db->query(
            'UPDATE IGNORE jobs SET status = ?, attempts = ?, unique_key = ?, available_at = ?, started_at = NULL, finished_at = NULL, last_error = COALESCE(?, last_error), updated_at = ? WHERE id = ?',
            [
                JobModel::STATUS_PENDING,
                $attempts,
                $this->uniqueKey((string) $job['type'], (string) $job['payload']),
                date('Y-m-d H:i:s', time() + $delaySeconds),
                $error === null ? null : mb_substr($error, 0, self::MAX_TEXT),
                $now,
                (int) $job['id'],
            ],
        );

        return $this->db->affectedRows() === 1;
    }

    private function markFailed(array $job, string $message): void
    {
        $now = date('Y-m-d H:i:s');
        $this->jobs->update((int) $job['id'], [
            'status'      => JobModel::STATUS_FAILED,
            'unique_key'  => null,
            'finished_at' => $now,
            'last_error'  => mb_substr($message, 0, self::MAX_TEXT),
        ]);

        $label = isset($this->config->jobs[$job['type']]) ? $this->config->jobs[$job['type']]::label() : $job['type'];
        $this->syncLogs->insert([
            'integration_type' => self::SYNC_INTEGRATION,
            'integration_id'   => (int) $job['id'],
            'action'           => mb_substr((string) $job['type'], 0, 60),
            'status'           => SyncLogModel::STATUS_ERROR,
            'message'          => mb_substr(sprintf('%s%s falhou após %d tentativa(s): %s', $label, $job['reference'] ? ' (' . $job['reference'] . ')' : '', $job['attempts'], $message), 0, self::MAX_TEXT),
            'payload'          => $job['payload'],
            'started_at'       => $job['started_at'] ?? $now,
            'finished_at'      => $now,
        ]);
    }

    /**
     * Mensagem para a tela: textos das regras de negócio e da API como estão;
     * erros inesperados levam o tipo para facilitar a busca no log do servidor.
     */
    private function messageFor(Throwable $e): string
    {
        $message = match (true) {
            $e instanceof MarketplaceApiError => $e->userMessage(),
            $e instanceof BusinessRuleException,
            $e instanceof UnsupportedOperationException,
            $e instanceof InvalidArgumentException => $e->getMessage(),
            $e instanceof ValidationException => implode(' ', $e->getErrors()),
            default => 'Erro inesperado (' . (new ReflectionClass($e))->getShortName() . '): ' . $e->getMessage(),
        };

        return mb_substr(trim($message) !== '' ? $message : 'Falha sem mensagem.', 0, 900);
    }

    private function uniqueKey(string $type, string $encodedPayload): string
    {
        return mb_substr($type, 0, 60) . ':' . sha1($encodedPayload);
    }

    private function duration(int $seconds): string
    {
        return $seconds >= 60 ? intdiv($seconds, 60) . ' min' : $seconds . ' s';
    }
}

<?php

namespace Tests\Database\Automation;

use App\Exceptions\BusinessRuleException;
use App\Jobs\RecalculateSupplierSelectionJob;
use App\Models\JobModel;
use App\Models\ScheduledTaskModel;
use App\Services\Automation\JobQueue;
use App\Services\Http\TransportException;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreApiException;
use App\Services\Suppliers\Connectors\UnsupportedOperationException;
use Config\Automation;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\ScriptedJob;

final class JobQueueTest extends DatabaseTestCase
{
    private JobQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        ScriptedJob::reset();

        $config       = new Automation();
        $config->jobs = [ScriptedJob::TYPE => ScriptedJob::class] + $config->jobs;
        $this->queue  = new JobQueue(config: $config);
    }

    public function testSameJobIsQueuedOnceWhileWaitingButAgainOnceRunning(): void
    {
        $first = $this->queue->enqueue(ScriptedJob::TYPE, ['listing_id' => 7, 'b' => 1]);
        $this->assertNotNull($first);
        $this->assertNull($this->queue->enqueue(ScriptedJob::TYPE, ['b' => 1, 'listing_id' => 7]), 'mesma carga em outra ordem é o mesmo job');
        $this->assertNotNull($this->queue->enqueue(ScriptedJob::TYPE, ['listing_id' => 8, 'b' => 1]));

        $claimed = $this->queue->claimNext();
        $this->assertSame($first, (int) $claimed['id']);
        $this->assertSame(JobModel::STATUS_RUNNING, $claimed['status']);
        $this->assertSame(1, (int) $claimed['attempts']);
        $this->assertNull($claimed['unique_key']);

        $this->assertNotNull($this->queue->enqueue(ScriptedJob::TYPE, ['listing_id' => 7, 'b' => 1]), 'enquanto executa, uma nova rodada pode entrar na fila');
    }

    public function testWorkRunsJobsAndRecordsWorkerHeartbeat(): void
    {
        ScriptedJob::$script = ['Tudo certo.'];
        $id                  = $this->queue->enqueue(ScriptedJob::TYPE, ['x' => 1], JobModel::SOURCE_MANUAL, 'REF-1');

        $stats = $this->queue->work(10, 10);

        $this->assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'failed' => 0, 'released' => 0], $stats);
        $this->assertSame([['x' => 1]], ScriptedJob::$payloads);
        $this->seeInDatabase('jobs', ['id' => $id, 'status' => JobModel::STATUS_COMPLETED, 'result' => 'Tudo certo.', 'reference' => 'REF-1']);
        $this->seeInDatabase('scheduled_tasks', ['name' => ScheduledTaskModel::WORKER, 'last_status' => 'success']);
    }

    public function testTemporaryFailureIsRetriedWithBackoffUntilAttemptsRunOut(): void
    {
        ScriptedJob::$script = [new TransportException('timeout'), new TransportException('timeout')];
        $id                  = $this->queue->enqueue(ScriptedJob::TYPE, ['x' => 1]);
        $this->db->table('jobs')->where('id', $id)->update(['max_attempts' => 2]);

        $stats = $this->queue->work(10, 10);
        $this->assertSame(1, $stats['retried']);

        $job = $this->db->table('jobs')->where('id', $id)->get()->getRowArray();
        $this->assertSame(JobModel::STATUS_PENDING, $job['status']);
        $this->assertSame(1, (int) $job['attempts']);
        $this->assertNotNull($job['unique_key'], 'na fila de novo, a chave única volta a valer');
        $this->assertStringContainsString('timeout', $job['last_error']);
        $this->assertEqualsWithDelta(time() + 60, strtotime($job['available_at']), 5);
        $this->assertNull($this->queue->enqueue(ScriptedJob::TYPE, ['x' => 1]), 'o job reagendado impede duplicata');

        $this->assertSame(0, $this->queue->work(10, 10)['processed'], 'ainda esperando a próxima tentativa');

        $this->db->table('jobs')->where('id', $id)->update(['available_at' => date('Y-m-d H:i:s', time() - 1)]);
        $stats = $this->queue->work(10, 10);
        $this->assertSame(1, $stats['failed']);

        $this->seeInDatabase('jobs', ['id' => $id, 'status' => JobModel::STATUS_FAILED, 'attempts' => 2]);
        $log = $this->db->table('sync_logs')->where('integration_type', JobQueue::SYNC_INTEGRATION)->get()->getRowArray();
        $this->assertSame('error', $log['status']);
        $this->assertSame((string) $id, (string) $log['integration_id']);
        $this->assertStringContainsString('falhou após 2 tentativa(s)', $log['message']);
    }

    public function testBusinessRuleFailsWithoutRetry(): void
    {
        ScriptedJob::$script = [new BusinessRuleException('Preço abaixo da margem mínima.')];
        $id                  = $this->queue->enqueue(ScriptedJob::TYPE);

        $this->assertSame(1, $this->queue->work(10, 10)['failed']);
        $this->seeInDatabase('jobs', ['id' => $id, 'status' => JobModel::STATUS_FAILED, 'attempts' => 1, 'last_error' => 'Preço abaixo da margem mínima.']);
    }

    public function testRetryClassificationFollowsTheExceptionChain(): void
    {
        $api = static fn (int $status, ?string $code = null) => new MercadoLivreApiException('erro', $status, $code);

        $this->assertTrue($this->queue->isRetryable(new BusinessRuleException('falhou', 0, $api(503))), '5xx dentro da regra de negócio');
        $this->assertTrue($this->queue->isRetryable(new BusinessRuleException('falhou', 0, $api(429))));
        $this->assertTrue($this->queue->isRetryable(new BusinessRuleException('falhou', 0, $api(0))), 'sem resposta');
        $this->assertFalse($this->queue->isRetryable(new BusinessRuleException('falhou', 0, $api(400))));
        $this->assertFalse($this->queue->isRetryable($api(400, 'invalid_grant')));
        $this->assertFalse($this->queue->isRetryable(new UnsupportedOperationException('sem adapter')));
        $this->assertFalse($this->queue->isRetryable(new BusinessRuleException('regra')));
        $this->assertTrue($this->queue->isRetryable(new TransportException('timeout')));
        $this->assertTrue($this->queue->isRetryable(new RuntimeException('inesperado')));
    }

    public function testBackoffDoublesUpToTheLimit(): void
    {
        $this->assertSame([60, 120, 240, 480, 960, 1920, 3600, 3600], array_map($this->queue->backoffSeconds(...), range(1, 8)));
    }

    public function testInterruptedJobGoesBackToTheQueue(): void
    {
        $id = $this->queue->enqueue(ScriptedJob::TYPE);
        $this->queue->claimNext();
        $this->db->table('jobs')->where('id', $id)->update(['started_at' => date('Y-m-d H:i:s', time() - 3600)]);

        $stats = $this->queue->work(10, 10);

        $this->assertSame(1, $stats['released']);
        $this->assertSame(1, $stats['completed'], 'liberado e executado na mesma rodada');
        $this->seeInDatabase('jobs', ['id' => $id, 'status' => JobModel::STATUS_COMPLETED, 'attempts' => 2]);
    }

    public function testRetryAndCancel(): void
    {
        ScriptedJob::$script = [new BusinessRuleException('falhou')];
        $failed              = $this->queue->enqueue(ScriptedJob::TYPE, ['x' => 1]);
        $this->queue->work(10, 10);

        $this->queue->retry($failed);
        $this->seeInDatabase('jobs', ['id' => $failed, 'status' => JobModel::STATUS_PENDING, 'attempts' => 0]);

        try {
            $this->queue->retry($failed);
            $this->fail('Só jobs com falha voltam para a fila.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Só jobs com falha', $e->getMessage());
        }

        $this->queue->cancel($failed);
        $this->seeInDatabase('jobs', ['id' => $failed, 'status' => JobModel::STATUS_CANCELLED]);
        $this->assertNotNull($this->queue->enqueue(ScriptedJob::TYPE, ['x' => 1]), 'cancelado libera a chave única');
        $this->assertSame(1, $this->queue->cancelAllPending());
    }

    public function testRealJobRecalculatesCatalog(): void
    {
        $queue = new JobQueue();
        $queue->enqueue(RecalculateSupplierSelectionJob::TYPE);

        $queue->work(10, 10);

        $this->seeInDatabase('jobs', ['type' => RecalculateSupplierSelectionJob::TYPE, 'status' => JobModel::STATUS_COMPLETED, 'result' => '0 produto(s) avaliado(s), 0 troca(s) de fornecedor principal.']);
    }
}

<?php

namespace Tests\Database\Automation;

use App\Exceptions\BusinessRuleException;
use App\Jobs\ProcessMercadoLivreNotificationsJob;
use App\Jobs\RecalculateSupplierSelectionJob;
use App\Models\ScheduledTaskModel;
use App\Services\Automation\Scheduler;
use Config\Services;
use Tests\Support\DatabaseTestCase;

final class SchedulerTest extends DatabaseTestCase
{
    private Scheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        service('settings')->seedDefaults();
        $this->scheduler = new Scheduler();
    }

    public function testRunsOnlyDueTasksAndRecordsHeartbeat(): void
    {
        $now     = time();
        $results = $this->scheduler->runDue($now);

        $this->assertArrayHasKey('supplier_selection', $results);
        $this->assertArrayNotHasKey('meli_stock', $results, 'atualização automática desligada por padrão');
        $this->assertArrayNotHasKey('meli_prices', $results);
        $this->assertSame('Nenhuma notificação na fila.', $results['meli_notifications']['message']);
        $this->assertSame(1, $this->db->table('jobs')->where('type', RecalculateSupplierSelectionJob::TYPE)->countAllResults());
        $this->seeInDatabase('scheduled_tasks', ['name' => ScheduledTaskModel::SCHEDULER, 'last_status' => 'success']);
        $this->assertFalse(service('jobQueue')->heartbeat()['scheduler_late']);

        $this->assertSame([], $this->scheduler->runDue($now + 30), 'nenhuma tarefa vence em 30 segundos');
        $this->assertSame(['meli_notifications'], array_keys($this->scheduler->runDue($now + 60)), 'só a tarefa de minuto em minuto');

        $later = $this->scheduler->runDue($now + 3600);
        $this->assertArrayHasKey('supplier_selection', $later);
        $this->assertSame('Nada novo: os jobs já estavam na fila.', $later['supplier_selection']['message'], 'o job anterior ainda não rodou');
    }

    public function testPendingNotificationsAreQueued(): void
    {
        $this->db->table('webhook_events')->insert([
            'provider' => 'mercadolivre', 'dedupe_key' => sha1('teste'), 'topic' => 'orders_v2', 'resource' => '/orders/1', 'user_id' => '123',
            'status' => 'pending', 'payload' => '{}', 'received_at' => date('Y-m-d H:i:s'),
        ]);

        $results = $this->scheduler->runDue();

        $this->assertSame('1 job(s) enfileirado(s).', $results['meli_notifications']['message']);
        $this->seeInDatabase('jobs', ['type' => ProcessMercadoLivreNotificationsJob::TYPE, 'reference' => '1 pendente(s)']);
    }

    public function testListingTasksDependOnTheAutoUpdateSettings(): void
    {
        $tasks = array_column($this->scheduler->overview(), null, 'name');
        $this->assertFalse($tasks['meli_stock']['enabled']);
        $this->assertStringContainsString('desligada', $tasks['meli_stock']['disabled_why']);

        try {
            $this->scheduler->runNow('meli_stock');
            $this->fail('Sem a configuração ligada a tarefa não roda.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Configurações', $e->getMessage());
        }

        $this->setSetting('sync.auto_update_stock', '1');
        $this->setSetting('sync.supplier_stock_interval', '0');
        $scheduler = new Scheduler();

        $tasks = array_column($scheduler->overview(), null, 'name');
        $this->assertTrue($tasks['meli_stock']['enabled']);
        $this->assertSame(30, $tasks['meli_stock']['interval']);
        $this->assertFalse($tasks['supplier_stock']['enabled'], 'intervalo zero desliga a tarefa');
        $this->assertSame('Nenhum anúncio com estoque diferente do fornecedor.', $scheduler->runNow('meli_stock')['message']);
    }

    public function testUnknownTaskIsRejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->scheduler->runNow('nao_existe');
    }

    private function setSetting(string $key, string $value): void
    {
        $this->db->table('settings')->where('key', $key)->update(['value' => $value]);
        Services::resetSingle('settings');
    }
}

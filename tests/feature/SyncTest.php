<?php

namespace Tests\Feature;

use App\DTO\MercadoLivre\TokenSet;
use App\Jobs\RecalculateSupplierSelectionJob;
use App\Models\JobModel;
use App\Services\Automation\JobQueue;
use App\Services\Marketplaces\MarketplaceAccountService;
use CodeIgniter\Test\Filters\CITestStreamFilter;
use Config\Services;
use Tests\Support\FakeMercadoLivreTransport;
use Tests\Support\FeatureTestCase;

final class SyncTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        service('settings')->seedDefaults();
    }

    public function testPageWarnsWhenSchedulerNeverRanAndListsTasks(): void
    {
        $page = $this->actingAs($this->createUser('operator'))->get('sync');

        $page->assertOK();
        $page->assertSee('O agendador nunca foi executado');
        $page->assertSee('php spark schedule:run &amp;&amp; php spark jobs:work');
        $page->assertSee('Recalcular fornecedores e preços');
        $page->assertSee('Atualização automática desligada nas configurações.');
        $page->assertSee('Nenhum fornecedor ativo tem adapter de integração');
        $page->assertSee('Nenhum job na fila ainda.');
        $page->assertDontSee('Cancelar fila');
    }

    public function testRunTaskNowQueuesJob(): void
    {
        $user   = $this->createUser('operator');
        $result = $this->actingAs($user)->post('sync/tasks/supplier_selection/run');

        $result->assertRedirectTo(site_url('sync'));
        $result->assertSessionHas('success', '1 job(s) enfileirado(s). O worker do cron executa em até um minuto.');
        $this->seeInDatabase('jobs', ['type' => RecalculateSupplierSelectionJob::TYPE, 'source' => JobModel::SOURCE_MANUAL, 'created_by' => $user['id']]);

        $blocked = $this->actingAs($user)->post('sync/tasks/meli_stock/run');
        $blocked->assertSessionHas('error');

        $page = $this->actingAs($user)->get('sync');
        $page->assertSee('Recálculo de fornecedores e preços');
        $page->assertSee('Painel');
    }

    public function testFailedJobCanBeRetriedAndBulkActionsAreForAdmins(): void
    {
        $queue = new JobQueue();
        $id    = $queue->enqueue(RecalculateSupplierSelectionJob::TYPE);
        $this->db->table('jobs')->where('id', $id)->update(['status' => JobModel::STATUS_FAILED, 'unique_key' => null, 'attempts' => 5, 'last_error' => 'Falha de teste']);

        $operator = $this->createUser('operator');
        $this->actingAs($operator)->get('sync?status=failed')->assertSee('Falha de teste');
        $this->actingAs($operator)->post('sync/jobs/retry-failed')->assertStatus(403);

        $this->actingAs($operator)->post("sync/jobs/{$id}/retry");
        $this->assertSame('Job devolvido à fila.', session('success'), (string) session('error'));
        $this->seeInDatabase('jobs', ['id' => $id, 'status' => JobModel::STATUS_PENDING, 'attempts' => 0]);

        Services::resetSingle('auth');
        $this->actingAs($this->createUser('admin'))->post('sync/jobs/cancel-pending');
        $this->assertSame('1 job(s) cancelado(s).', session('success'));
        $this->seeInDatabase('jobs', ['id' => $id, 'status' => JobModel::STATUS_CANCELLED]);
    }

    public function testCommandsQueueAndWorkJobs(): void
    {
        CITestStreamFilter::registration();
        CITestStreamFilter::addOutputFilter();

        try {
            command('schedule:run');
            command('jobs:work');
            command('meli:sync-stock');
            command('suppliers:sync');
            $output = CITestStreamFilter::$buffer;
        } finally {
            CITestStreamFilter::removeOutputFilter();
        }

        $this->assertStringContainsString('supplier_selection: 1 job(s) enfileirado(s).', $output);
        $this->assertStringContainsString('Recálculo de fornecedores e preços: 0 produto(s) avaliado(s)', $output);
        $this->assertStringContainsString('atualização automática de estoque está desligada', $output);
        $this->assertStringContainsString('Nenhum fornecedor ativo com adapter de integração', $output);
        $this->seeInDatabase('scheduled_tasks', ['name' => 'worker']);
    }

    public function testDashboardShowsAutomationAlertsAndNewCards(): void
    {
        $queue = new JobQueue();
        $id    = $queue->enqueue(RecalculateSupplierSelectionJob::TYPE);
        $this->db->table('jobs')->where('id', $id)->update(['status' => JobModel::STATUS_FAILED, 'unique_key' => null]);
        $account = (new MarketplaceAccountService())->storeConnection('mercadolivre', TokenSet::fromResponse(FakeMercadoLivreTransport::tokenResponse()), 'LOJA_TESTE', 'MLB', null);
        $this->db->table('marketplace_accounts')->where('id', $account['id'])->update(['token_expires_at' => date('Y-m-d H:i:s', time() + 600)]);

        $page = $this->actingAs($this->createUser('operator'))->get('dashboard');

        $page->assertOK();
        $page->assertSee('Anúncios ativos');
        $page->assertSee('O agendador nunca rodou');
        $page->assertSee('1 job(s) de sincronização falharam');
        $page->assertSee('1 conta(s) do Mercado Livre com token vencendo nos próximos minutos');
        $page->assertSee('Vendas dos últimos 14 dias');
        $page->assertSee('Produtos com maior lucro (30 dias)');
        $page->assertSee('Pedidos recentes');
        $page->assertSee('Fase 9');

        service('scheduler')->runDue();
        $this->actingAs($this->createUser('operator'))->get('dashboard')->assertDontSee('O agendador nunca rodou');
    }
}

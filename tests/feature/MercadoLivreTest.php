<?php

namespace Tests\Feature;

use App\DTO\MercadoLivre\TokenSet;
use App\Services\Marketplaces\MarketplaceAccountService;
use CodeIgniter\Config\Factories;
use Config\MercadoLivre;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use Tests\Support\FakeMercadoLivreTransport;
use Tests\Support\FeatureTestCase;

final class MercadoLivreTest extends FeatureTestCase
{
    use StreamFilterTrait;

    private const ACCESS = 'APP_USR-1111-acesso-original-123456';

    private FakeMercadoLivreTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new FakeMercadoLivreTransport();
        Services::injectMock('mercadoLivreTransport', $this->transport);
    }

    public function testPageExplainsMissingConfiguration(): void
    {
        $this->configure(false);

        $result = $this->actingAs($this->createUser('admin'))->get('marketplaces/mercadolivre');

        $result->assertOK();
        $result->assertSee('Integração não configurada');
        $result->assertSee('MELI_CLIENT_ID');
        $result->assertSee(site_url('marketplaces/mercadolivre/callback'));
        $result->assertSee('Nenhuma conta conectada ainda.');
    }

    public function testOnlyAdminsManageAccounts(): void
    {
        $this->configure();
        $operator = $this->createUser('operator');

        $page = $this->actingAs($operator)->get('marketplaces/mercadolivre');
        $page->assertOK();
        $page->assertDontSee('Conectar conta');

        $this->actingAs($operator)->post('marketplaces/mercadolivre/connect')->assertStatus(403);
        $this->actingAs($operator)->get('marketplaces/mercadolivre/callback?code=x&state=y')->assertStatus(403);
        $this->actingAs($operator)->post('marketplaces/mercadolivre/accounts/1/disconnect')->assertStatus(403);
    }

    public function testConnectRedirectsToOfficialAuthorizationPage(): void
    {
        $this->configure();

        $result = $this->actingAs($this->createUser('admin'))->post('marketplaces/mercadolivre/connect');

        $result->assertRedirect();
        $location = $result->response()->getHeaderLine('Location');
        $this->assertStringStartsWith('https://auth.mercadolivre.com.br/authorization?response_type=code&client_id=APP123', $location);
        $this->assertStringContainsString('state=', $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);
    }

    public function testCallbackConnectsAccountAndScreenShowsOnlyMaskedToken(): void
    {
        $this->configure();
        $admin = $this->createUser('admin');
        $state = str_repeat('f', 64);

        $this->transport
            ->push(200, FakeMercadoLivreTransport::tokenResponse())
            ->push(200, ['id' => 123456, 'nickname' => 'LOJA_TESTE', 'site_id' => 'MLB']);

        $callback = $this->withSession([
            'user_id'    => $admin['id'],
            'user_role'  => 'admin',
            'meli_oauth' => ['state' => $state, 'code_verifier' => 'verificador-teste', 'expires_at' => time() + 300],
        ])->get('marketplaces/mercadolivre/callback?code=TG-code-abc&state=' . $state);

        $callback->assertRedirectTo(site_url('marketplaces/mercadolivre'));
        $callback->assertSessionHas('success', 'Conta LOJA_TESTE conectada ao Mercado Livre.');
        $this->assertSame('verificador-teste', $this->transport->formBody(0)['code_verifier']);

        $page = $this->actingAs($admin)->get('marketplaces/mercadolivre');
        $page->assertSee('LOJA_TESTE');
        $page->assertSee('APP_USR-••••3456');
        $page->assertDontSee(self::ACCESS);
        $page->assertDontSee('TG-refresh-original');
        $page->assertSee('Conectada');

        $api = $this->actingAs($admin)->get('api/marketplaces/accounts');
        $api->assertOK();
        $this->assertStringNotContainsString('APP_USR', $api->getJSON());
        $this->assertStringNotContainsString('encrypted', $api->getJSON());
        $this->assertSame('LOJA_TESTE', json_decode($api->getJSON(), true)['data'][0]['name']);
    }

    public function testCallbackWithForgedStateShowsError(): void
    {
        $this->configure();
        $admin = $this->createUser('admin');

        $result = $this->withSession([
            'user_id'    => $admin['id'],
            'user_role'  => 'admin',
            'meli_oauth' => ['state' => str_repeat('a', 64), 'expires_at' => time() + 300],
        ])->get('marketplaces/mercadolivre/callback?code=TG-code&state=' . str_repeat('b', 64));

        $result->assertRedirectTo(site_url('marketplaces/mercadolivre'));
        $this->assertStringContainsString('state não confere', (string) session('error'));
        $this->assertSame([], $this->transport->requests);
        $this->dontSeeInDatabase('marketplace_accounts', ['external_user_id' => '123456']);
    }

    public function testDisconnectAndDashboardAlert(): void
    {
        $this->configure();
        $admin = $this->createUser('admin');
        $id    = $this->storeAccount();

        $this->db->table('marketplace_accounts')->where('id', $id)->update(['status' => 'reauthorization_required']);
        $this->actingAs($admin)->get('dashboard')->assertSee('precisam ser reconectadas');

        $this->transport->push(200, []);
        $result = $this->actingAs($admin)->post("marketplaces/mercadolivre/accounts/{$id}/disconnect");

        $result->assertRedirectTo(site_url('marketplaces/mercadolivre'));
        $this->seeInDatabase('marketplace_accounts', ['id' => $id, 'status' => 'disconnected', 'access_token_encrypted' => null]);
        $this->actingAs($admin)->get('dashboard')->assertDontSee('precisam ser reconectadas');
    }

    public function testCategoryExplorer(): void
    {
        $this->configure();
        $operator = $this->createUser('operator');

        $this->actingAs($operator)->get('marketplaces/mercadolivre/categories')->assertSee('Conecte uma conta do Mercado Livre');

        $this->storeAccount();
        $this->transport->push(200, [['id' => 'MLB1051', 'name' => 'Celulares e Telefones']]);

        $page = $this->actingAs($operator)->get('marketplaces/mercadolivre/categories');
        $page->assertOK();
        $page->assertSee('Celulares e Telefones');

        $invalid = $this->actingAs($operator)->get('marketplaces/mercadolivre/categories?category=invalida');
        $invalid->assertSee('ID de categoria do Mercado Livre inválido');
    }

    public function testRefreshCommand(): void
    {
        $this->configure();
        $id = $this->storeAccount();

        $this->transport->push(200, FakeMercadoLivreTransport::tokenResponse('APP_USR-2222-cron-123456', 'TG-cron-123456'));
        command('meli:refresh-token --force');

        $this->assertStringContainsString("Conta #{$id} (LOJA_TESTE): token válido até", $this->getStreamFilterBuffer());
        $this->seeInDatabase('sync_logs', ['action' => 'token_refresh', 'status' => 'success', 'integration_id' => $id]);
    }

    private function configure(bool $configured = true): void
    {
        $config               = new MercadoLivre();
        $config->clientId     = $configured ? 'APP123' : '';
        $config->clientSecret = $configured ? 'segredo-do-app' : '';
        $config->redirectUri  = $configured ? site_url('marketplaces/mercadolivre/callback') : '';
        $config->usePkce      = true;

        Factories::injectMock('config', MercadoLivre::class, $config);
        Services::resetSingle('mercadoLivre');
    }

    private function storeAccount(): int
    {
        $tokens = TokenSet::fromResponse(FakeMercadoLivreTransport::tokenResponse());

        return (new MarketplaceAccountService())->storeConnection('mercadolivre', $tokens, 'LOJA_TESTE', 'MLB', null)['id'];
    }
}

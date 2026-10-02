<?php

namespace Tests\Database\MercadoLivre;

use App\Exceptions\BusinessRuleException;
use App\Models\MarketplaceAccountModel;
use App\Services\Marketplaces\MarketplaceAccountService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreApiClient;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreApiException;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreAuthService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreService;
use App\Services\Marketplaces\MercadoLivre\Pkce;
use CodeIgniter\I18n\Time;
use Config\MercadoLivre;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeMercadoLivreTransport;

final class MercadoLivreAuthServiceTest extends DatabaseTestCase
{
    private const ACCESS  = 'APP_USR-1111-acesso-original-123456';
    private const REFRESH = 'TG-refresh-original-123456';

    private MercadoLivre $config;
    private FakeMercadoLivreTransport $transport;
    private MercadoLivreAuthService $auth;
    private MarketplaceAccountService $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        Time::setTestNow('2026-10-02 10:00:00');

        $this->config               = new MercadoLivre();
        $this->config->clientId     = 'APP123';
        $this->config->clientSecret = 'segredo-do-app';
        $this->config->redirectUri  = 'http://localhost:8080/marketplaces/mercadolivre/callback';
        $this->config->usePkce      = true;

        $this->transport = new FakeMercadoLivreTransport();
        $this->accounts  = new MarketplaceAccountService();
        $this->auth      = new MercadoLivreAuthService($this->config, new MercadoLivreApiClient($this->config, $this->transport), $this->accounts);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    public function testAuthorizationUrlCarriesStateAndPkce(): void
    {
        $url = $this->auth->authorizationUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $pending = session('meli_oauth');

        $this->assertStringStartsWith('https://auth.mercadolivre.com.br/authorization?', $url);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('APP123', $query['client_id']);
        $this->assertSame($this->config->redirectUri, $query['redirect_uri']);
        $this->assertSame($pending['state'], $query['state']);
        $this->assertSame(64, strlen($query['state']));
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(Pkce::challenge($pending['code_verifier']), $query['code_challenge']);
        $this->assertStringNotContainsString('segredo-do-app', $url);
    }

    public function testRefusesToStartWithoutCredentials(): void
    {
        $this->config->clientSecret = '';

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('MELI_CLIENT_SECRET');
        $this->auth->authorizationUrl();
    }

    public function testCallbackStoresEncryptedTokensAndProfile(): void
    {
        $user = $this->createUser('admin');
        $this->connect($user['id']);

        $token = $this->transport->formBody(0);
        $this->assertSame('authorization_code', $token['grant_type']);
        $this->assertSame('CODE-123', $token['code']);
        $this->assertSame('segredo-do-app', $token['client_secret']);
        $this->assertSame($this->config->redirectUri, $token['redirect_uri']);
        $this->assertArrayHasKey('code_verifier', $token);
        $this->assertSame('https://api.mercadolibre.com/oauth/token', $this->transport->requests[0]['url']);

        $profile = $this->transport->requests[1];
        $this->assertSame('https://api.mercadolibre.com/users/123456', $profile['url']);
        $this->assertSame('Bearer ' . self::ACCESS, $profile['headers']['Authorization']);

        $row = $this->db->table('marketplace_accounts')->get()->getRowArray();
        $this->assertSame('LOJA_TESTE', $row['name']);
        $this->assertSame('MLB', $row['site_id']);
        $this->assertSame('123456', $row['external_user_id']);
        $this->assertSame('connected', $row['status']);
        $this->assertSame('2026-10-02 16:00:00', $row['token_expires_at']);
        $this->assertSame((string) $user['id'], (string) $row['connected_by']);
        $this->assertStringNotContainsString(self::ACCESS, $row['access_token_encrypted']);
        $this->assertStringNotContainsString(self::REFRESH, $row['refresh_token_encrypted']);
        $this->assertSame(self::ACCESS, $this->accounts->accessToken($row));
        $this->assertSame(self::REFRESH, $this->accounts->refreshToken($row));
        $this->assertNull(session('meli_oauth'), 'state é de uso único');

        $this->assertNoTokenLeaks();
        $this->seeInDatabase('sync_logs', ['integration_type' => 'mercadolivre', 'action' => 'oauth_connect', 'status' => 'success']);
        $this->seeInDatabase('audit_logs', ['entity_type' => 'marketplace_account', 'action' => 'connect']);
    }

    public function testCallbackWithWrongStateIsDiscardedWithoutCallingTheApi(): void
    {
        $this->auth->authorizationUrl();

        try {
            $this->auth->handleCallback(['code' => 'CODE-123', 'state' => str_repeat('a', 64)], null);
            $this->fail('state inválido deveria ser recusado');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('state', $e->getMessage());
        }

        $this->assertSame([], $this->transport->requests);
        $this->assertNull(session('meli_oauth'));
        $this->seeInDatabase('sync_logs', ['action' => 'oauth_connect', 'status' => 'error']);
    }

    public function testCallbackWithExpiredOrMissingStateIsRejected(): void
    {
        $this->auth->authorizationUrl();
        $state = session('meli_oauth')['state'];

        Time::setTestNow('2026-10-02 10:11:00');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('expirou');
        $this->auth->handleCallback(['code' => 'CODE-123', 'state' => $state], null);
    }

    public function testCallbackTranslatesTokenExchangeErrors(): void
    {
        $this->auth->authorizationUrl();
        $state = session('meli_oauth')['state'];
        $this->transport->push(400, ['error' => 'invalid_operator_user_id', 'message' => 'operator user']);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('conta administradora');
        $this->auth->handleCallback(['code' => 'CODE-123', 'state' => $state], null);
    }

    public function testReconnectingSameUserUpdatesTheSameAccount(): void
    {
        $this->connect();
        $this->connect(access: 'APP_USR-2222-acesso-novo-123456', refresh: 'TG-refresh-novo-123456');

        $this->assertSame(1, $this->db->table('marketplace_accounts')->countAllResults());
        $row = $this->db->table('marketplace_accounts')->get()->getRowArray();
        $this->assertSame('APP_USR-2222-acesso-novo-123456', $this->accounts->accessToken($row));
        $this->seeInDatabase('audit_logs', ['entity_type' => 'marketplace_account', 'action' => 'reconnect']);
    }

    public function testRefreshRotatesBothTokens(): void
    {
        $id = $this->connect();
        Time::setTestNow('2026-10-02 15:45:00');

        $this->transport->push(200, FakeMercadoLivreTransport::tokenResponse('APP_USR-3333-renovado-123456', 'TG-refresh-renovado-123456'));
        $account = $this->auth->refresh($id);

        $body = $this->transport->formBody(2);
        $this->assertSame('refresh_token', $body['grant_type']);
        $this->assertSame(self::REFRESH, $body['refresh_token']);
        $this->assertSame('APP_USR-3333-renovado-123456', $this->accounts->accessToken($account));
        $this->assertSame('TG-refresh-renovado-123456', $this->accounts->refreshToken($account));
        $this->assertSame('2026-10-02 21:45:00', $account['token_expires_at']);
        $this->assertNoTokenLeaks();
        $this->seeInDatabase('sync_logs', ['action' => 'token_refresh', 'status' => 'success', 'integration_id' => $id]);
    }

    public function testRefreshIsSkippedWhileTokenIsFreshUnlessForced(): void
    {
        $id = $this->connect();

        $this->auth->refresh($id);
        $this->assertCount(2, $this->transport->requests, 'token novo não deve ser renovado');

        $this->transport->push(200, FakeMercadoLivreTransport::tokenResponse('APP_USR-4444-forcado-123456', 'TG-forcado-123456'));
        $this->auth->refresh($id, true);
        $this->assertCount(3, $this->transport->requests);
    }

    public function testInvalidGrantRequiresReconnection(): void
    {
        $id = $this->connect();
        $this->transport->push(400, ['error' => 'invalid_grant', 'error_description' => 'Error validating grant.', 'status' => 400]);

        try {
            $this->auth->refresh($id, true);
            $this->fail('invalid_grant deveria interromper a renovação');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Conecte a conta novamente', $e->getMessage());
        }

        $this->seeInDatabase('marketplace_accounts', ['id' => $id, 'status' => MarketplaceAccountModel::STATUS_REAUTHORIZE]);
        $this->assertSame(['reconnect' => 1, 'expired' => 0, 'expiring' => 0], $this->accounts->attentionSummary());

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('não está conectada');
        $this->auth->refresh($id, true);
    }

    public function testTemporaryFailureKeepsAccountConnected(): void
    {
        $id = $this->connect();
        $this->transport->pushFailure('Operation timed out');

        try {
            $this->auth->refresh($id, true);
            $this->fail('Falha de rede deveria ser informada');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Não foi possível falar', $e->getMessage());
        }

        $row = $this->db->table('marketplace_accounts')->where('id', $id)->get()->getRowArray();
        $this->assertSame('connected', $row['status']);
        $this->assertStringContainsString('Não foi possível falar', $row['last_error']);
        $this->assertSame(self::REFRESH, $this->accounts->refreshToken($row), 'refresh token antigo continua salvo');
    }

    public function testServiceRenewsAutomaticallyBeforeExpiring(): void
    {
        $id   = $this->connect();
        $meli = new MercadoLivreService($this->config, new MercadoLivreApiClient($this->config, $this->transport), $this->auth, $this->accounts);

        $this->assertSame(self::ACCESS, $meli->accessToken($id));
        $this->assertCount(2, $this->transport->requests);

        Time::setTestNow('2026-10-02 15:40:00');
        $this->transport->push(200, FakeMercadoLivreTransport::tokenResponse('APP_USR-5555-auto-123456', 'TG-auto-123456'));

        $this->assertSame('APP_USR-5555-auto-123456', $meli->accessToken($id));
    }

    public function testServiceRetriesOnceWhenTokenIsRejected(): void
    {
        $id   = $this->connect();
        $meli = new MercadoLivreService($this->config, new MercadoLivreApiClient($this->config, $this->transport), $this->auth, $this->accounts);

        $this->transport
            ->push(401, ['message' => 'invalid access token', 'error' => 'not_found', 'status' => 401])
            ->push(200, FakeMercadoLivreTransport::tokenResponse('APP_USR-6666-novo-123456', 'TG-novo-123456'))
            ->push(200, [['id' => 'MLB5672', 'name' => 'Acessórios para Veículos']]);

        $result = $meli->get($id, '/sites/MLB/categories');

        $this->assertSame('MLB5672', $result[0]['id']);
        $this->assertSame('Bearer APP_USR-6666-novo-123456', end($this->transport->requests)['headers']['Authorization']);
    }

    public function testRefreshRejectsTokenFromAnotherUser(): void
    {
        $id = $this->connect();
        $this->transport->push(200, FakeMercadoLivreTransport::tokenResponse('APP_USR-7777-outro-999', 'TG-outro-999', 999));

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('outro usuário');

        try {
            $this->auth->refresh($id, true);
        } finally {
            $row = $this->db->table('marketplace_accounts')->where('id', $id)->get()->getRowArray();
            $this->assertSame(self::ACCESS, $this->accounts->accessToken($row));
        }
    }

    public function testDisconnectRevokesAndWipesTokens(): void
    {
        $id = $this->connect();
        $this->transport->push(200, []);

        $this->assertTrue($this->auth->disconnect($id));

        $request = $this->transport->requests[2];
        $this->assertSame('DELETE', $request['method']);
        $this->assertSame('https://api.mercadolibre.com/users/123456/applications/APP123', $request['url']);

        $row = $this->db->table('marketplace_accounts')->where('id', $id)->get()->getRowArray();
        $this->assertNull($row['access_token_encrypted']);
        $this->assertNull($row['refresh_token_encrypted']);
        $this->assertSame('disconnected', $row['status']);
        $this->assertSame('0', (string) $row['active']);
        $this->seeInDatabase('audit_logs', ['entity_type' => 'marketplace_account', 'action' => 'disconnect']);
    }

    public function testDisconnectStillWipesTokensWhenRevocationFails(): void
    {
        $id = $this->connect();
        $this->transport->push(500, ['message' => 'internal error']);

        $this->assertFalse($this->auth->disconnect($id));
        $this->seeInDatabase('marketplace_accounts', ['id' => $id, 'status' => 'disconnected', 'access_token_encrypted' => null]);
        $this->seeInDatabase('sync_logs', ['action' => 'oauth_disconnect', 'status' => 'warning']);
    }

    private function connect(?int $userId = null, string $access = self::ACCESS, string $refresh = self::REFRESH): int
    {
        $this->auth->authorizationUrl();
        $state = session('meli_oauth')['state'];

        $this->transport
            ->push(200, FakeMercadoLivreTransport::tokenResponse($access, $refresh))
            ->push(200, ['id' => 123456, 'nickname' => 'LOJA_TESTE', 'site_id' => 'MLB', 'country_id' => 'BR']);

        return $this->auth->handleCallback(['code' => 'CODE-123', 'state' => $state], $userId)['id'];
    }

    /**
     * Nenhum token em texto puro em logs de sincronização ou auditoria.
     */
    private function assertNoTokenLeaks(): void
    {
        $logs = json_encode([
            $this->db->table('sync_logs')->get()->getResultArray(),
            $this->db->table('audit_logs')->get()->getResultArray(),
        ]);

        foreach (['APP_USR-', 'TG-', 'segredo-do-app', '_encrypted'] as $secret) {
            $this->assertStringNotContainsString($secret, $logs, "Vazamento de '{$secret}' nos logs");
        }
    }
}

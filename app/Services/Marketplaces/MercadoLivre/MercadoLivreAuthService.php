<?php

namespace App\Services\Marketplaces\MercadoLivre;

use App\DTO\MercadoLivre\TokenSet;
use App\Exceptions\BusinessRuleException;
use App\Models\MarketplaceAccountModel;
use App\Models\SyncLogModel;
use App\Services\Marketplaces\MarketplaceAccountService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Config\MercadoLivre;
use Throwable;

/**
 * OAuth 2.0 (Authorization Code, server side) do Mercado Livre:
 * autorização com state + PKCE, troca do code, renovação e revogação.
 *
 * Fluxo e parâmetros conforme
 * https://developers.mercadolivre.com.br/pt_br/autenticacao-e-autorizacao
 */
class MercadoLivreAuthService
{
    public const SLUG             = 'mercadolivre';
    public const SYNC_INTEGRATION = 'mercadolivre';

    private const SESSION_KEY = 'meli_oauth';

    private readonly MercadoLivre $config;
    private readonly MercadoLivreApiClient $api;
    private readonly MarketplaceAccountService $accounts;
    private readonly SyncLogModel $syncLogs;
    private readonly BaseConnection $db;

    public function __construct(
        ?MercadoLivre $config = null,
        ?MercadoLivreApiClient $api = null,
        ?MarketplaceAccountService $accounts = null,
        ?SyncLogModel $syncLogs = null,
        ?BaseConnection $db = null,
    ) {
        $this->config   = $config ?? config(MercadoLivre::class);
        $this->api      = $api ?? new MercadoLivreApiClient($this->config);
        $this->accounts = $accounts ?? new MarketplaceAccountService();
        $this->syncLogs = $syncLogs ?? new SyncLogModel();
        $this->db       = $db ?? db_connect();
    }

    /**
     * Gera state e code_verifier, guarda na sessão e devolve a URL de autorização.
     */
    public function authorizationUrl(): string
    {
        $this->assertConfigured();

        $state   = bin2hex(random_bytes(32));
        $pending = ['state' => $state, 'expires_at' => Time::now()->getTimestamp() + $this->config->stateTtlSeconds];
        $params  = [
            'response_type' => 'code',
            'client_id'     => $this->config->clientId,
            'redirect_uri'  => $this->config->redirectUri,
            'state'         => $state,
        ];

        if ($this->config->usePkce) {
            $pending['code_verifier']        = Pkce::verifier();
            $params['code_challenge']        = Pkce::challenge($pending['code_verifier']);
            $params['code_challenge_method'] = Pkce::METHOD;
        }

        session()->set(self::SESSION_KEY, $pending);

        return $this->config->authUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Trata o retorno do Mercado Livre na redirect_uri.
     *
     * @param array<string, mixed> $query parâmetros da URL de retorno
     *
     * @return array{id: int, created: bool, name: string}
     */
    public function handleCallback(array $query, ?int $userId): array
    {
        $this->assertConfigured();

        // O state vale uma única vez, com sucesso ou não.
        $pending = session(self::SESSION_KEY);
        session()->remove(self::SESSION_KEY);

        $state = is_string($query['state'] ?? null) ? $query['state'] : '';

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < Time::now()->getTimestamp()) {
            throw new BusinessRuleException('A autorização expirou ou não foi iniciada por este painel. Clique em "Conectar conta" novamente.');
        }

        if ($state === '' || ! hash_equals((string) $pending['state'], $state)) {
            $this->logSync(null, 'oauth_connect', SyncLogModel::STATUS_ERROR, 'Retorno de autorização com state inválido; requisição descartada.');

            throw new BusinessRuleException('Retorno de autorização inválido (state não confere). Por segurança, a conexão foi descartada.');
        }

        if (isset($query['error'])) {
            $error = mb_substr((string) preg_replace('/[^\w .:-]/u', '', (string) $query['error']), 0, 80);
            $this->logSync(null, 'oauth_connect', SyncLogModel::STATUS_WARNING, 'Autorização não concluída no Mercado Livre: ' . $error);

            throw new BusinessRuleException('O Mercado Livre não concluiu a autorização (' . $error . ').');
        }

        $code = is_string($query['code'] ?? null) ? trim($query['code']) : '';
        if ($code === '') {
            throw new BusinessRuleException('O Mercado Livre não devolveu o código de autorização.');
        }

        $params = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
            'code'          => $code,
            'redirect_uri'  => $this->config->redirectUri,
        ];
        if (isset($pending['code_verifier'])) {
            $params['code_verifier'] = $pending['code_verifier'];
        }

        $startedAt = date('Y-m-d H:i:s');

        try {
            $tokens = TokenSet::fromResponse($this->api->requestToken($params));
        } catch (MercadoLivreApiException $e) {
            $this->logSync(null, 'oauth_connect', SyncLogModel::STATUS_ERROR, 'Falha na troca do code por token: ' . $e->userMessage(), $startedAt);

            throw new BusinessRuleException($e->userMessage(), 0, $e);
        }

        [$name, $siteId] = $this->profile($tokens);
        $result          = $this->accounts->storeConnection(self::SLUG, $tokens, $name, $siteId, $userId);

        $message = $result['created'] ? 'Conta conectada' : 'Conta reconectada';
        if ($tokens->refreshToken === null) {
            $message .= '; a resposta não trouxe refresh_token (confira se o aplicativo tem o escopo offline_access)';
        }
        $this->logSync($result['id'], 'oauth_connect', $tokens->refreshToken === null ? SyncLogModel::STATUS_WARNING : SyncLogModel::STATUS_SUCCESS, $message . '.', $startedAt, [
            'external_user_id' => $tokens->userId,
            'scope'            => $tokens->scope,
            'expires_in'       => $tokens->expiresIn,
        ]);

        return $result + ['name' => $name];
    }

    /**
     * Renova o access token com o refresh token.
     *
     * O refresh token é de uso único: duas renovações simultâneas fariam a
     * segunda falhar e derrubar a conta. Por isso a linha fica travada
     * (SELECT ... FOR UPDATE) e a necessidade de renovar é conferida de novo
     * depois de obter a trava; quem chegar em segundo usa o token já renovado.
     *
     * @return array<string, mixed> conta atualizada (registro completo)
     */
    public function refresh(int $accountId, bool $force = false): array
    {
        $this->assertConfigured();

        $this->db->transBegin();
        $open = true;

        try {
            $account = $this->db->query('SELECT * FROM marketplace_accounts WHERE id = ? FOR UPDATE', [$accountId])->getRowArray();

            if ($account === null) {
                throw new BusinessRuleException('Conta de marketplace não encontrada.');
            }

            if ($account['status'] !== MarketplaceAccountModel::STATUS_CONNECTED || (int) $account['active'] !== 1) {
                throw new BusinessRuleException('A conta "' . $account['name'] . '" não está conectada. Use "Reconectar" para autorizar novamente.');
            }

            if (! $force && ! $this->needsRefresh($account)) {
                $this->db->transCommit();
                $open = false;

                return $this->accounts->findOrFail($accountId);
            }

            $startedAt    = date('Y-m-d H:i:s');
            $refreshToken = $this->accounts->refreshToken($account);

            if ($refreshToken === null) {
                $reason = 'Sem refresh token salvo; é preciso conectar a conta novamente.';
                $this->accounts->markReauthorizationRequired($accountId, $reason);
                $this->db->transCommit();
                $open = false;
                $this->logSync($accountId, 'token_refresh', SyncLogModel::STATUS_ERROR, $reason, $startedAt);

                throw new BusinessRuleException($reason);
            }

            try {
                $tokens = TokenSet::fromResponse($this->api->requestToken([
                    'grant_type'    => 'refresh_token',
                    'client_id'     => $this->config->clientId,
                    'client_secret' => $this->config->clientSecret,
                    'refresh_token' => $refreshToken,
                ]));
            } catch (MercadoLivreApiException $e) {
                if ($e->requiresReauthorization()) {
                    $this->accounts->markReauthorizationRequired($accountId, $e->userMessage());
                } else {
                    $this->accounts->recordError($accountId, $e->userMessage());
                }
                $this->db->transCommit();
                $open = false;
                $this->logSync($accountId, 'token_refresh', SyncLogModel::STATUS_ERROR, 'Falha ao renovar o token: ' . $e->userMessage(), $startedAt);

                throw new BusinessRuleException($e->userMessage(), 0, $e);
            }

            if ($tokens->userId !== (string) $account['external_user_id']) {
                $this->db->transRollback();
                $open = false;
                $this->logSync($accountId, 'token_refresh', SyncLogModel::STATUS_ERROR, 'Token renovado pertence a outro usuário; resposta descartada.', $startedAt);

                throw new BusinessRuleException('O Mercado Livre devolveu um token de outro usuário. A renovação foi descartada.');
            }

            $saved = $this->accounts->storeRefresh($accountId, $tokens);
            $this->db->transCommit();
            $open = false;

            $this->logSync($accountId, 'token_refresh', SyncLogModel::STATUS_SUCCESS, 'Token renovado; vence em ' . date('d/m/Y H:i', strtotime($saved['token_expires_at'])) . '.', $startedAt, [
                'token_expires_at' => $saved['token_expires_at'],
                'forced'           => $force,
            ]);

            return $this->accounts->findOrFail($accountId);
        } catch (Throwable $e) {
            if ($open) {
                $this->db->transRollback();
            }

            throw $e;
        }
    }

    /**
     * Revoga a permissão no Mercado Livre (melhor esforço) e apaga os tokens locais.
     *
     * @return bool se a revogação remota foi confirmada
     */
    public function disconnect(int $accountId): bool
    {
        $account = $this->accounts->findOrFail($accountId);
        $revoked = false;
        $token   = null;

        try {
            $token = $this->accounts->accessToken($account);
        } catch (BusinessRuleException) {
            // Token ilegível: não há como revogar remotamente, mas ainda dá para limpar localmente.
        }

        if ($token !== null && $this->config->clientId !== '' && ! $this->expired($account)) {
            try {
                $this->api->delete('/users/' . rawurlencode((string) $account['external_user_id']) . '/applications/' . rawurlencode($this->config->clientId), $token);
                $revoked = true;
            } catch (MercadoLivreApiException $e) {
                log_message('warning', 'Revogação remota da conta {id} falhou: {message}', ['id' => $accountId, 'message' => $e->getMessage()]);
            }
        }

        $this->accounts->disconnect($accountId);
        $this->logSync($accountId, 'oauth_disconnect', $revoked ? SyncLogModel::STATUS_SUCCESS : SyncLogModel::STATUS_WARNING, $revoked
            ? 'Conta desconectada e permissão revogada no Mercado Livre.'
            : 'Conta desconectada localmente; a permissão não foi revogada no Mercado Livre.');

        return $revoked;
    }

    public function needsRefresh(array $account): bool
    {
        $expiresAt = $account['token_expires_at'] ?? null;

        return $expiresAt === null
            || strtotime($expiresAt) <= Time::now()->getTimestamp() + $this->config->refreshBeforeSeconds;
    }

    private function expired(array $account): bool
    {
        $expiresAt = $account['token_expires_at'] ?? null;

        return $expiresAt === null || strtotime($expiresAt) <= Time::now()->getTimestamp();
    }

    /**
     * Apelido e site do vendedor (GET /users/{user_id}). Se a consulta falhar,
     * a conexão segue com um nome provisório; o token já é válido.
     *
     * @return array{0: string, 1: ?string}
     */
    private function profile(TokenSet $tokens): array
    {
        try {
            $user = $this->api->get('/users/' . rawurlencode($tokens->userId), $tokens->accessToken);
        } catch (MercadoLivreApiException $e) {
            log_message('warning', 'Não foi possível ler o perfil do usuário {user} no Mercado Livre: {message}', ['user' => $tokens->userId, 'message' => $e->getMessage()]);

            return ['Conta ' . $tokens->userId, null];
        }

        $nickname = is_string($user['nickname'] ?? null) && $user['nickname'] !== '' ? $user['nickname'] : 'Conta ' . $tokens->userId;
        $siteId   = is_string($user['site_id'] ?? null) ? $user['site_id'] : null;

        return [$nickname, $siteId];
    }

    private function assertConfigured(): void
    {
        if (! $this->config->isConfigured()) {
            throw new BusinessRuleException('Integração com o Mercado Livre não configurada. Preencha ' . implode(', ', $this->config->missingSettings()) . ' no .env.');
        }
    }

    /**
     * @param array<string, mixed>|null $payload nunca deve conter tokens
     */
    private function logSync(?int $accountId, string $action, string $status, string $message, ?string $startedAt = null, ?array $payload = null): void
    {
        $this->syncLogs->insert([
            'integration_type' => self::SYNC_INTEGRATION,
            'integration_id'   => $accountId,
            'action'           => $action,
            'status'           => $status,
            'message'          => mb_substr($message, 0, 1000),
            'payload'          => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'started_at'       => $startedAt ?? date('Y-m-d H:i:s'),
            'finished_at'      => date('Y-m-d H:i:s'),
        ]);
    }
}

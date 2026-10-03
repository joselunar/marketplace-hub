<?php

namespace App\Services\Marketplaces\MercadoLivre;

use App\Exceptions\BusinessRuleException;
use App\Services\Marketplaces\MarketplaceAccountService;
use Config\MercadoLivre;

/**
 * Porta de entrada das chamadas autenticadas: entrega um access token
 * válido (renovando quando está perto de vencer) e repete a chamada uma
 * vez se o Mercado Livre recusar o token antes do prazo.
 */
class MercadoLivreService
{
    private readonly MercadoLivre $config;
    private readonly MercadoLivreApiClient $api;
    private readonly MercadoLivreAuthService $auth;
    private readonly MarketplaceAccountService $accounts;

    public function __construct(
        ?MercadoLivre $config = null,
        ?MercadoLivreApiClient $api = null,
        ?MercadoLivreAuthService $auth = null,
        ?MarketplaceAccountService $accounts = null,
    ) {
        $this->config   = $config ?? config(MercadoLivre::class);
        $this->api      = $api ?? new MercadoLivreApiClient($this->config);
        $this->accounts = $accounts ?? new MarketplaceAccountService();
        $this->auth     = $auth ?? new MercadoLivreAuthService($this->config, $this->api, $this->accounts);
    }

    public function config(): MercadoLivre
    {
        return $this->config;
    }

    public function auth(): MercadoLivreAuthService
    {
        return $this->auth;
    }

    public function accounts(): MarketplaceAccountService
    {
        return $this->accounts;
    }

    /**
     * Conta a usar numa consulta: a informada ou a primeira conectada.
     */
    public function resolveAccount(?int $accountId = null): array
    {
        if ($accountId !== null) {
            $account = $this->accounts->findOrFail($accountId);
            if ($account['marketplace_slug'] !== MercadoLivreAuthService::SLUG) {
                throw new BusinessRuleException('A conta informada não é do Mercado Livre.');
            }

            return $account;
        }

        $account = $this->accounts->firstConnected(MercadoLivreAuthService::SLUG);
        if ($account === null) {
            throw new BusinessRuleException('Nenhuma conta do Mercado Livre conectada. Conecte uma conta para consultar a API.');
        }

        return $this->accounts->findOrFail((int) $account['id']);
    }

    /**
     * Access token válido da conta, renovado automaticamente se necessário.
     */
    public function accessToken(int $accountId): string
    {
        $account = $this->accounts->findOrFail($accountId);

        if ($this->auth->needsRefresh($account)) {
            $account = $this->auth->refresh($accountId);
        }

        $token = $this->accounts->accessToken($account);
        if ($token === null) {
            throw new BusinessRuleException('A conta "' . $account['name'] . '" não tem token. Conecte-a novamente.');
        }

        return $token;
    }

    /**
     * GET autenticado. Em 401 renova o token uma vez e tenta de novo.
     *
     * @param array<string, scalar> $query
     */
    public function get(int $accountId, string $path, array $query = []): array
    {
        return $this->withToken($accountId, fn (string $token): array => $this->api->get($path, $token, $query));
    }

    /**
     * @param array<string, mixed> $body
     */
    public function post(int $accountId, string $path, array $body): array
    {
        return $this->withToken($accountId, fn (string $token): array => $this->api->post($path, $token, $body));
    }

    /**
     * @param array<string, mixed> $body
     */
    public function put(int $accountId, string $path, array $body): array
    {
        return $this->withToken($accountId, fn (string $token): array => $this->api->put($path, $token, $body));
    }

    public function delete(int $accountId, string $path): array
    {
        return $this->withToken($accountId, fn (string $token): array => $this->api->delete($path, $token));
    }

    /**
     * Tags do vendedor (GET /users/{user_id}). "user_product_seller" indica
     * que a conta já publica no modelo User Products.
     *
     * @return array{tags: list<string>, user_products: bool}
     */
    public function seller(array $account, bool $fresh = false): array
    {
        $key   = 'meli_seller_' . (int) $account['id'];
        $cache = service('cache');

        if (! $fresh) {
            $cached = $cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $user = $this->get((int) $account['id'], '/users/' . rawurlencode((string) $account['external_user_id']));
        $tags = array_values(array_filter($user['tags'] ?? [], 'is_string'));
        $info = ['tags' => $tags, 'user_products' => in_array('user_product_seller', $tags, true)];

        $cache->save($key, $info, $this->config->sellerCacheSeconds);

        return $info;
    }

    /**
     * Executa a chamada com o token da conta. Em 401 renova uma vez e repete.
     *
     * Um 401 em POST não cria o recurso, então repetir é seguro.
     *
     * @param callable(string): array $call
     */
    private function withToken(int $accountId, callable $call): array
    {
        try {
            return $call($this->accessToken($accountId));
        } catch (MercadoLivreApiException $e) {
            if ($e->status !== 401) {
                throw $e;
            }
        }

        $this->auth->refresh($accountId, true);

        return $call($this->accessToken($accountId));
    }

    public function siteFor(array $account): string
    {
        $site = $account['site_id'] ?? null;

        return is_string($site) && $site !== '' ? $site : $this->config->siteId;
    }
}

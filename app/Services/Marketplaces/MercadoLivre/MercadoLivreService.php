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
        try {
            return $this->api->get($path, $this->accessToken($accountId), $query);
        } catch (MercadoLivreApiException $e) {
            if ($e->status !== 401) {
                throw $e;
            }
        }

        $this->auth->refresh($accountId, true);

        return $this->api->get($path, $this->accessToken($accountId), $query);
    }

    public function siteFor(array $account): string
    {
        $site = $account['site_id'] ?? null;

        return is_string($site) && $site !== '' ? $site : $this->config->siteId;
    }
}

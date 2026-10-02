<?php

namespace App\Services\Marketplaces;

use App\DTO\MercadoLivre\TokenSet;
use App\Exceptions\BusinessRuleException;
use App\Models\MarketplaceAccountModel;
use App\Models\MarketplaceModel;
use App\Services\Audit\AuditService;
use App\Services\Security\TokenCipher;
use CodeIgniter\I18n\Time;

/**
 * Contas conectadas a marketplaces. Único ponto que lê ou grava as colunas
 * de token; tudo que sai daqui para telas e API passa por present().
 */
class MarketplaceAccountService
{
    public function __construct(
        private readonly MarketplaceModel $marketplaces = new MarketplaceModel(),
        private readonly MarketplaceAccountModel $accounts = new MarketplaceAccountModel(),
        private readonly TokenCipher $cipher = new TokenCipher(),
        private readonly ?AuditService $audit = null,
    ) {
    }

    /**
     * Registro do marketplace, criado a partir de Config\Marketplaces se ainda não existir.
     */
    public function marketplace(string $slug): array
    {
        $marketplace = $this->marketplaces->findBySlug($slug);
        if ($marketplace !== null) {
            return $marketplace;
        }

        $registry = service('marketplaces');
        if (! $registry->has($slug)) {
            throw new BusinessRuleException('Marketplace desconhecido: ' . $slug);
        }

        $this->marketplaces->insert(['name' => $registry->get($slug)->name(), 'slug' => $slug, 'active' => 1]);

        return $this->marketplaces->find((int) $this->marketplaces->getInsertID());
    }

    /**
     * Contas do marketplace prontas para exibição (sem tokens).
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(string $slug): array
    {
        $marketplace = $this->marketplace($slug);
        $rows        = $this->accounts->withMarketplace()
            ->forMarketplace((int) $marketplace['id'])
            ->orderBy('marketplace_accounts.active', 'DESC')
            ->orderBy('marketplace_accounts.name')
            ->findAll();

        return array_map($this->present(...), $rows);
    }

    /**
     * Registro completo, inclusive colunas cifradas. Uso interno dos services.
     */
    public function findOrFail(int $id): array
    {
        $account = $this->accounts->withMarketplace()->where('marketplace_accounts.id', $id)->first();

        if ($account === null) {
            throw new BusinessRuleException('Conta de marketplace não encontrada.');
        }

        return $account;
    }

    /**
     * Primeira conta conectada do marketplace, usada nas consultas que só precisam de um token válido.
     */
    public function firstConnected(string $slug): ?array
    {
        $marketplace = $this->marketplace($slug);

        return $this->accounts->forMarketplace((int) $marketplace['id'])
            ->where('status', MarketplaceAccountModel::STATUS_CONNECTED)
            ->where('active', 1)
            ->orderBy('id')
            ->first();
    }

    /**
     * Contas conectadas e ativas, de um marketplace ou de todos, prontas para exibição.
     *
     * @return list<array<string, mixed>>
     */
    public function connected(?string $slug = null): array
    {
        $query = $this->accounts->withMarketplace()
            ->where('marketplace_accounts.status', MarketplaceAccountModel::STATUS_CONNECTED)
            ->where('marketplace_accounts.active', 1)
            ->orderBy('marketplaces.name')
            ->orderBy('marketplace_accounts.name');

        if ($slug !== null) {
            $query->where('marketplaces.slug', $slug);
        }

        return array_map($this->present(...), $query->findAll());
    }

    /**
     * Cria ou reconecta a conta (mesmo usuário externo = mesma linha).
     *
     * @return array{id: int, created: bool}
     */
    public function storeConnection(string $slug, TokenSet $tokens, string $name, ?string $siteId, ?int $userId): array
    {
        $marketplace = $this->marketplace($slug);
        $now         = Time::now()->getTimestamp();
        $data        = [
            'marketplace_id'          => (int) $marketplace['id'],
            'name'                    => mb_substr($name, 0, 150),
            'external_user_id'        => $tokens->userId,
            'site_id'                 => $siteId !== null ? mb_substr($siteId, 0, 10) : null,
            'access_token_encrypted'  => $this->cipher->encrypt($tokens->accessToken),
            'refresh_token_encrypted' => $tokens->refreshToken !== null ? $this->cipher->encrypt($tokens->refreshToken) : null,
            'token_expires_at'        => $tokens->expiresAt($now),
            'scope'                   => $tokens->scope,
            'status'                  => MarketplaceAccountModel::STATUS_CONNECTED,
            'active'                  => 1,
            'last_error'              => null,
            'token_refreshed_at'      => date('Y-m-d H:i:s', $now),
            'connected_by'            => $userId,
            'connected_at'            => date('Y-m-d H:i:s', $now),
        ];

        $existing = $this->accounts->where('marketplace_id', $marketplace['id'])
            ->where('external_user_id', $tokens->userId)
            ->first();

        if ($existing !== null) {
            $this->accounts->update($existing['id'], $data);
            // Sem valores antigos: a reconexão é registrada mesmo quando nada visível mudou (só os tokens).
            $this->audit()->log('reconnect', 'marketplace_account', (int) $existing['id'], null, MarketplaceAccountModel::withoutSecrets($data));

            return ['id' => (int) $existing['id'], 'created' => false];
        }

        $this->accounts->insert($data);
        $id = (int) $this->accounts->getInsertID();
        $this->audit()->log('connect', 'marketplace_account', $id, null, MarketplaceAccountModel::withoutSecrets($data));

        return ['id' => $id, 'created' => true];
    }

    /**
     * Grava o par de tokens devolvido por uma renovação.
     *
     * @return array<string, mixed> valores gravados (sem tokens)
     */
    public function storeRefresh(int $id, TokenSet $tokens): array
    {
        $now  = Time::now()->getTimestamp();
        $data = [
            'access_token_encrypted'  => $this->cipher->encrypt($tokens->accessToken),
            'refresh_token_encrypted' => $tokens->refreshToken !== null ? $this->cipher->encrypt($tokens->refreshToken) : null,
            'token_expires_at'        => $tokens->expiresAt($now),
            'scope'                   => $tokens->scope,
            'last_error'              => null,
            'token_refreshed_at'      => date('Y-m-d H:i:s', $now),
        ];
        $this->accounts->update($id, $data);

        return MarketplaceAccountModel::withoutSecrets($data);
    }

    public function markReauthorizationRequired(int $id, string $reason): void
    {
        $this->accounts->update($id, [
            'status'     => MarketplaceAccountModel::STATUS_REAUTHORIZE,
            'last_error' => mb_substr($reason, 0, 500),
        ]);
    }

    public function recordError(int $id, string $message): void
    {
        $this->accounts->update($id, ['last_error' => mb_substr($message, 0, 500)]);
    }

    /**
     * Apaga os tokens e desativa a conta. O histórico (logs, auditoria) continua apontando para ela.
     */
    public function disconnect(int $id): void
    {
        $account = $this->findOrFail($id);
        $data    = [
            'access_token_encrypted'  => null,
            'refresh_token_encrypted' => null,
            'token_expires_at'        => null,
            'status'                  => MarketplaceAccountModel::STATUS_DISCONNECTED,
            'active'                  => 0,
            'last_error'              => null,
        ];
        $this->accounts->update($id, $data);

        $this->audit()->log('disconnect', 'marketplace_account', $id, MarketplaceAccountModel::withoutSecrets($account), MarketplaceAccountModel::withoutSecrets($data));
    }

    public function accessToken(array $account): ?string
    {
        return $this->cipher->decrypt($account['access_token_encrypted'] ?? null);
    }

    public function refreshToken(array $account): ?string
    {
        return $this->cipher->decrypt($account['refresh_token_encrypted'] ?? null);
    }

    /**
     * Contas que pedem ação: reconexão manual, token vencido ou prestes a
     * vencer sem ter sido renovado (renovação automática parada).
     *
     * @param int $expiringMinutes a renovação agendada acontece bem antes disso
     *
     * @return array{reconnect: int, expired: int, expiring: int}
     */
    public function attentionSummary(int $expiringMinutes = 15): array
    {
        $now = Time::now()->toDateTimeString();

        return [
            'reconnect' => $this->accounts->where('status', MarketplaceAccountModel::STATUS_REAUTHORIZE)->where('active', 1)->countAllResults(),
            'expired'   => $this->accounts->where('status', MarketplaceAccountModel::STATUS_CONNECTED)
                ->where('active', 1)
                ->where('token_expires_at <', $now)
                ->countAllResults(),
            'expiring'  => $this->accounts->where('status', MarketplaceAccountModel::STATUS_CONNECTED)
                ->where('active', 1)
                ->where('token_expires_at >=', $now)
                ->where('token_expires_at <', date('Y-m-d H:i:s', Time::now()->getTimestamp() + $expiringMinutes * 60))
                ->countAllResults(),
        ];
    }

    /**
     * Dados seguros para tela/API: sem colunas cifradas, com o token mascarado.
     */
    public function present(array $account): array
    {
        $masked = '—';
        if (! empty($account['access_token_encrypted'])) {
            try {
                $masked = TokenCipher::mask($this->accessToken($account));
            } catch (BusinessRuleException) {
                $masked = 'ilegível (reconecte a conta)';
            }
        }

        $expiresAt = $account['token_expires_at'] ?? null;
        $data      = MarketplaceAccountModel::withoutSecrets($account);

        $data['access_token_masked'] = $masked;
        $data['has_refresh_token']   = ! empty($account['refresh_token_encrypted']);
        $data['token_expired']       = $expiresAt !== null && strtotime($expiresAt) < Time::now()->getTimestamp();

        return $data;
    }

    private function audit(): AuditService
    {
        return $this->audit ?? service('audit');
    }
}

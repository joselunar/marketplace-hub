<?php

namespace App\Models;

use CodeIgniter\Model;

class MarketplaceAccountModel extends Model
{
    public const STATUS_CONNECTED    = 'connected';
    public const STATUS_REAUTHORIZE  = 'reauthorization_required';
    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_LABELS = [
        self::STATUS_CONNECTED    => 'Conectada',
        self::STATUS_REAUTHORIZE  => 'Reconectar',
        self::STATUS_DISCONNECTED => 'Desconectada',
    ];

    /**
     * Colunas que nunca saem do model para telas, API, auditoria ou logs.
     */
    public const SECRET_FIELDS = ['access_token_encrypted', 'refresh_token_encrypted'];

    protected $table         = 'marketplace_accounts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'marketplace_id', 'name', 'external_user_id', 'site_id',
        'access_token_encrypted', 'refresh_token_encrypted', 'token_expires_at', 'scope',
        'status', 'active', 'last_error', 'token_refreshed_at', 'connected_by', 'connected_at',
    ];

    public function withMarketplace(): static
    {
        $this->select('marketplace_accounts.*, marketplaces.name AS marketplace_name, marketplaces.slug AS marketplace_slug, users.name AS connected_by_name')
            ->join('marketplaces', 'marketplaces.id = marketplace_accounts.marketplace_id')
            ->join('users', 'users.id = marketplace_accounts.connected_by', 'left');

        return $this;
    }

    public function forMarketplace(int $marketplaceId): static
    {
        $this->where('marketplace_accounts.marketplace_id', $marketplaceId);

        return $this;
    }

    /**
     * Contas conectadas cujo token vence até o instante informado.
     */
    public function expiringBefore(string $dateTime): static
    {
        $this->where('marketplace_accounts.status', self::STATUS_CONNECTED)
            ->where('marketplace_accounts.active', 1)
            ->groupStart()
                ->where('marketplace_accounts.token_expires_at <=', $dateTime)
                ->orWhere('marketplace_accounts.token_expires_at', null)
            ->groupEnd();

        return $this;
    }

    /**
     * Remove as colunas cifradas de um registro antes de exibi-lo.
     */
    public static function withoutSecrets(array $account): array
    {
        return array_diff_key($account, array_flip(self::SECRET_FIELDS));
    }
}

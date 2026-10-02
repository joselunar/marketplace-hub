<?php

namespace Tests\Support;

use App\Services\Marketplaces\Contracts\AbstractMarketplaceAdapter;
use App\Services\Marketplaces\MarketplaceRegistry;
use Config\Database;
use Config\Marketplaces;
use Config\Services;

/**
 * Segundo marketplace para os testes da interface comum. As capacidades e
 * as chamadas recebidas ficam em propriedades estáticas, porque o registro
 * cria a própria instância.
 */
final class FakeMarketplaceAdapter extends AbstractMarketplaceAdapter
{
    public const SLUG = 'teste';

    /**
     * @var list<string>
     */
    public static array $supported = [];

    public static bool $configured = true;

    /**
     * @var list<array{0: string, 1: int|string}>
     */
    public static array $calls = [];

    /**
     * Registra o adaptador ao lado do Mercado Livre.
     *
     * @param list<string> $capabilities
     */
    public static function register(array $capabilities, bool $configured = true): void
    {
        self::$supported  = $capabilities;
        self::$configured = $configured;
        self::$calls      = [];

        $config                       = new Marketplaces();
        $config->adapters[self::SLUG] = self::class;
        Services::injectMock('marketplaces', new MarketplaceRegistry($config));
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Marketplace de Teste';
    }

    public function capabilities(): array
    {
        return self::$supported;
    }

    public function isConfigured(): bool
    {
        return self::$configured;
    }

    public function missingSettings(): array
    {
        return self::$configured ? [] : ['TESTE_APP_ID'];
    }

    public function accountsPath(): string
    {
        return 'marketplaces';
    }

    public function refreshBeforeSeconds(): int
    {
        return 3600;
    }

    public function refreshToken(int $accountId, bool $force = false): array
    {
        if (! $this->supports(self::CAPABILITY_TOKEN_REFRESH)) {
            return parent::refreshToken($accountId, $force);
        }

        self::$calls[] = ['refresh', $accountId];
        $db            = Database::connect();
        $db->table('marketplace_accounts')->where('id', $accountId)->update(['token_expires_at' => date('Y-m-d H:i:s', time() + 21600)]);

        return $db->table('marketplace_accounts')->where('id', $accountId)->get()->getRowArray();
    }

    public function importRecentOrders(int $accountId, int $days, ?int $userId = null): array
    {
        if (! $this->supports(self::CAPABILITY_ORDERS)) {
            return parent::importRecentOrders($accountId, $days, $userId);
        }

        self::$calls[] = ['orders', $accountId];

        return ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'errors' => []];
    }
}

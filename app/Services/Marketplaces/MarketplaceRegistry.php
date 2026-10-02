<?php

namespace App\Services\Marketplaces;

use App\Exceptions\BusinessRuleException;
use App\Services\Marketplaces\Contracts\MarketplaceAdapter;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Marketplaces;
use LogicException;

/**
 * Adaptadores registrados em Config\Marketplaces e a resolução do adaptador
 * a partir de uma conta ou de um anúncio.
 */
class MarketplaceRegistry
{
    /**
     * @var array<string, MarketplaceAdapter>|null
     */
    private ?array $adapters = null;

    private readonly Marketplaces $config;

    public function __construct(?Marketplaces $config = null, private readonly ?BaseConnection $db = null)
    {
        $this->config = $config ?? config(Marketplaces::class);
    }

    /**
     * @return array<string, MarketplaceAdapter> por slug
     */
    public function all(): array
    {
        if ($this->adapters !== null) {
            return $this->adapters;
        }

        $this->adapters = [];
        foreach ($this->config->adapters as $slug => $class) {
            $adapter = new $class();
            if (! $adapter instanceof MarketplaceAdapter || $adapter->slug() !== $slug) {
                throw new LogicException(sprintf('Adaptador de marketplace inválido para "%s": %s.', $slug, $class));
            }
            $this->adapters[$slug] = $adapter;
        }

        return $this->adapters;
    }

    public function has(string $slug): bool
    {
        return isset($this->config->adapters[$slug]);
    }

    public function get(string $slug): MarketplaceAdapter
    {
        return $this->all()[$slug] ?? throw new BusinessRuleException('Marketplace sem integração: ' . $slug);
    }

    /**
     * @return array<string, string> slug => nome
     */
    public function names(): array
    {
        return array_map(static fn (MarketplaceAdapter $adapter): string => $adapter->name(), $this->all());
    }

    /**
     * @return array<string, MarketplaceAdapter>
     */
    public function supporting(string $capability): array
    {
        return array_filter($this->all(), static fn (MarketplaceAdapter $adapter): bool => $adapter->supports($capability));
    }

    public function forAccount(int $accountId): MarketplaceAdapter
    {
        $row = $this->db()->table('marketplace_accounts a')
            ->select('m.slug')
            ->join('marketplaces m', 'm.id = a.marketplace_id')
            ->where('a.id', $accountId)
            ->get()
            ->getRowArray();

        if ($row === null) {
            throw new BusinessRuleException('Conta de marketplace não encontrada.');
        }

        return $this->get((string) $row['slug']);
    }

    public function forListing(int $listingId): MarketplaceAdapter
    {
        $row = $this->db()->table('marketplace_listings l')
            ->select('m.slug')
            ->join('marketplace_accounts a', 'a.id = l.marketplace_account_id')
            ->join('marketplaces m', 'm.id = a.marketplace_id')
            ->where('l.id', $listingId)
            ->get()
            ->getRowArray();

        if ($row === null) {
            throw new BusinessRuleException('Anúncio não encontrado.');
        }

        return $this->get((string) $row['slug']);
    }

    private function db(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}

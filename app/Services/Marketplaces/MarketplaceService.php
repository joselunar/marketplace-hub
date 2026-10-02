<?php

namespace App\Services\Marketplaces;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\MarketplaceAccountModel;
use App\Models\MarketplaceListingModel;
use App\Models\MarketplaceModel;
use App\Services\Audit\AuditService;
use App\Services\Marketplaces\Contracts\MarketplaceAdapter;
use App\Services\Pricing\PricingService;
use App\Support\Decimal;
use Config\Database;

/**
 * Visão geral dos marketplaces integrados e tarifas padrão de cada um.
 */
class MarketplaceService
{
    public function __construct(
        private readonly ?MarketplaceRegistry $registry = null,
        private readonly MarketplaceAccountService $accounts = new MarketplaceAccountService(),
        private readonly MarketplaceModel $marketplaces = new MarketplaceModel(),
        private readonly ?PricingService $pricing = null,
        private readonly ?AuditService $audit = null,
    ) {
    }

    /**
     * Um item por adaptador registrado, com contas, anúncios e tarifas em vigor.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(): array
    {
        $accounts = $this->accountCounts();
        $listings = $this->activeListings();
        $list     = [];

        foreach ($this->registry()->all() as $slug => $adapter) {
            $row       = $this->accounts->marketplace($slug);
            $effective = $this->pricing()->config($slug);

            $list[] = [
                'slug'            => $slug,
                'name'            => $adapter->name(),
                'configured'      => $adapter->isConfigured(),
                'missing'         => $adapter->missingSettings(),
                'capabilities'    => array_combine($adapter->capabilities(), array_map(
                    static fn (string $capability): string => MarketplaceAdapter::CAPABILITY_LABELS[$capability] ?? $capability,
                    $adapter->capabilities(),
                )),
                'accounts_path'   => $adapter->accountsPath(),
                'listings_path'   => $adapter->listingsPath(),
                'connected'       => $accounts[$slug]['connected'] ?? 0,
                'reconnect'       => $accounts[$slug]['reconnect'] ?? 0,
                'active_listings' => $listings[$slug] ?? 0,
                'fees'            => array_intersect_key($row, MarketplaceModel::FEE_FIELDS),
                'effective_fees'  => [
                    'fee_percentage'      => $effective->marketplaceFeePercentage,
                    'fixed_fee'           => $effective->marketplaceFixedFee,
                    'fixed_fee_threshold' => $effective->fixedFeeThreshold,
                ],
            ];
        }

        return $list;
    }

    /**
     * Grava as tarifas padrão. Campo vazio volta a herdar o valor global.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, string|null> valores gravados
     */
    public function updateFees(string $slug, array $input, ?int $userId = null): array
    {
        if (! $this->registry()->has($slug)) {
            throw new BusinessRuleException('Marketplace sem integração: ' . $slug);
        }

        $row    = $this->accounts->marketplace($slug);
        $data   = [];
        $errors = [];

        foreach (MarketplaceModel::FEE_FIELDS as $field => $label) {
            $raw = $input[$field] ?? null;
            $raw = is_string($raw) ? trim($raw) : $raw;

            if ($raw === null || $raw === '') {
                $data[$field] = null;

                continue;
            }

            $value = Decimal::normalize($raw);
            if ($value === null || bccomp($value, '0', 2) < 0) {
                $errors[$field] = "{$label}: informe um valor numérico maior ou igual a zero.";

                continue;
            }
            if ($field === 'fee_percentage' && bccomp($value, '100', 2) >= 0) {
                $errors[$field] = "{$label}: informe um percentual menor que 100.";

                continue;
            }
            if ($field !== 'fee_percentage' && bccomp($value, '99999999.99', 2) > 0) {
                $errors[$field] = "{$label}: valor alto demais.";

                continue;
            }

            $data[$field] = $value;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $old = array_intersect_key($row, MarketplaceModel::FEE_FIELDS);
        $this->marketplaces->update((int) $row['id'], $data);
        $this->audit()->log('update_fees', 'marketplace', (int) $row['id'], $old, $data, $userId);

        return $data;
    }

    /**
     * @return array<string, array{connected: int, reconnect: int}>
     */
    private function accountCounts(): array
    {
        $rows = Database::connect()->table('marketplace_accounts a')
            ->select('m.slug, a.status, COUNT(*) AS total')
            ->join('marketplaces m', 'm.id = a.marketplace_id')
            ->where('a.active', 1)
            ->whereIn('a.status', [MarketplaceAccountModel::STATUS_CONNECTED, MarketplaceAccountModel::STATUS_REAUTHORIZE])
            ->groupBy('m.slug, a.status')
            ->get()
            ->getResultArray();

        $counts = [];
        foreach ($rows as $row) {
            $key                        = $row['status'] === MarketplaceAccountModel::STATUS_CONNECTED ? 'connected' : 'reconnect';
            $counts[$row['slug']][$key] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function activeListings(): array
    {
        $rows = Database::connect()->table('marketplace_listings l')
            ->select('m.slug, COUNT(*) AS total')
            ->join('marketplace_accounts a', 'a.id = l.marketplace_account_id')
            ->join('marketplaces m', 'm.id = a.marketplace_id')
            ->where('l.status', MarketplaceListingModel::STATUS_ACTIVE)
            ->groupBy('m.slug')
            ->get()
            ->getResultArray();

        return array_column(array_map(static fn (array $row): array => [$row['slug'], (int) $row['total']], $rows), 1, 0);
    }

    private function registry(): MarketplaceRegistry
    {
        return $this->registry ?? service('marketplaces');
    }

    private function pricing(): PricingService
    {
        return $this->pricing ?? service('pricing');
    }

    private function audit(): AuditService
    {
        return $this->audit ?? service('audit');
    }
}

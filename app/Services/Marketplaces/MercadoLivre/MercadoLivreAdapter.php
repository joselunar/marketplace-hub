<?php

namespace App\Services\Marketplaces\MercadoLivre;

use App\Services\Marketplaces\Contracts\AbstractMarketplaceAdapter;
use Config\MercadoLivre;

/**
 * Mercado Livre visto pela interface comum. Só repassa as chamadas aos
 * services do módulo, que continuam sendo a implementação de fato.
 */
class MercadoLivreAdapter extends AbstractMarketplaceAdapter
{
    public function slug(): string
    {
        return MercadoLivreAuthService::SLUG;
    }

    public function name(): string
    {
        return 'Mercado Livre';
    }

    public function capabilities(): array
    {
        return [
            self::CAPABILITY_TOKEN_REFRESH,
            self::CAPABILITY_LISTINGS,
            self::CAPABILITY_STOCK,
            self::CAPABILITY_PRICE,
            self::CAPABILITY_ORDERS,
            self::CAPABILITY_NOTIFICATIONS,
        ];
    }

    public function isConfigured(): bool
    {
        return $this->config()->isConfigured();
    }

    public function missingSettings(): array
    {
        return array_values($this->config()->missingSettings());
    }

    public function accountsPath(): string
    {
        return 'marketplaces/mercadolivre';
    }

    public function listingsPath(): ?string
    {
        return 'marketplaces/mercadolivre/listings';
    }

    public function refreshBeforeSeconds(): int
    {
        return $this->config()->refreshBeforeSeconds;
    }

    public function refreshToken(int $accountId, bool $force = false): array
    {
        return service('mercadoLivre')->auth()->refresh($accountId, $force);
    }

    public function findListing(int $listingId): array
    {
        return service('mercadoLivreProducts')->findOrFail($listingId);
    }

    public function updateListingStock(int $listingId, ?int $userId = null, bool $skipUnchanged = false): array
    {
        return service('mercadoLivreProducts')->updateStock($listingId, $userId, $skipUnchanged);
    }

    public function updateListingPrice(int $listingId, ?int $userId = null, bool $skipUnchanged = false): array
    {
        return service('mercadoLivreProducts')->updatePrice($listingId, $userId, $skipUnchanged);
    }

    public function importOrder(int $accountId, string $externalOrderId): array
    {
        return service('mercadoLivreOrders')->import($accountId, $externalOrderId);
    }

    public function importRecentOrders(int $accountId, int $days, ?int $userId = null): array
    {
        return service('mercadoLivreOrders')->importRecent($accountId, $days, $userId);
    }

    public function pendingNotifications(): int
    {
        return service('mercadoLivreNotifications')->pendingCount();
    }

    public function processNotifications(int $limit): array
    {
        return service('mercadoLivreNotifications')->processPending($limit);
    }

    private function config(): MercadoLivre
    {
        return service('mercadoLivre')->config();
    }
}

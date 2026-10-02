<?php

namespace App\Services\Marketplaces\Contracts;

/**
 * Base para adaptadores: cada operação recusa por padrão, então um novo
 * marketplace implementa só o que declarou em capabilities().
 */
abstract class AbstractMarketplaceAdapter implements MarketplaceAdapter
{
    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function listingsPath(): ?string
    {
        return null;
    }

    public function refreshBeforeSeconds(): int
    {
        return 0;
    }

    public function refreshToken(int $accountId, bool $force = false): array
    {
        throw UnsupportedMarketplaceOperation::for($this, self::CAPABILITY_TOKEN_REFRESH);
    }

    public function findListing(int $listingId): array
    {
        throw UnsupportedMarketplaceOperation::for($this, self::CAPABILITY_LISTINGS);
    }

    public function updateListingStock(int $listingId, ?int $userId = null, bool $skipUnchanged = false): array
    {
        throw UnsupportedMarketplaceOperation::for($this, self::CAPABILITY_STOCK);
    }

    public function updateListingPrice(int $listingId, ?int $userId = null, bool $skipUnchanged = false): array
    {
        throw UnsupportedMarketplaceOperation::for($this, self::CAPABILITY_PRICE);
    }

    public function importOrder(int $accountId, string $externalOrderId): array
    {
        throw UnsupportedMarketplaceOperation::for($this, self::CAPABILITY_ORDERS);
    }

    public function importRecentOrders(int $accountId, int $days, ?int $userId = null): array
    {
        throw UnsupportedMarketplaceOperation::for($this, self::CAPABILITY_ORDERS);
    }

    public function pendingNotifications(): int
    {
        return 0;
    }

    public function processNotifications(int $limit): array
    {
        throw UnsupportedMarketplaceOperation::for($this, self::CAPABILITY_NOTIFICATIONS);
    }
}

<?php

namespace App\Jobs;

/**
 * Envia ao anúncio o estoque seguro do fornecedor principal, pelo
 * adaptador do marketplace da conta.
 */
class SyncListingStockJob extends AbstractJob
{
    public const TYPE = 'listing_stock';

    public static function label(): string
    {
        return 'Estoque do anúncio';
    }

    public function handle(array $payload): string
    {
        $listingId = $this->id($payload, 'listing_id');
        $adapter   = service('marketplaces')->forListing($listingId);
        $before    = $adapter->findListing($listingId);
        $after     = $adapter->updateListingStock($listingId, null, true);

        if ($before['available_quantity'] !== null && (int) $before['available_quantity'] === (int) $after['available_quantity'] && $after['last_sync_at'] === $before['last_sync_at']) {
            return sprintf('%s: estoque já estava correto (%d).', $adapter->name(), (int) $after['available_quantity']);
        }

        return sprintf('%s: estoque enviado, %s → %d.', $adapter->name(), $before['available_quantity'] ?? '—', (int) $after['available_quantity']);
    }
}

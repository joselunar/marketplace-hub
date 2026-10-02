<?php

namespace App\Jobs;

/**
 * Recalcula o preço com a tarifa do marketplace e envia ao anúncio.
 * Preço abaixo da margem mínima é recusado (o job falha sem nova tentativa).
 */
class SyncListingPriceJob extends AbstractJob
{
    public const TYPE = 'listing_price';

    public static function label(): string
    {
        return 'Preço do anúncio';
    }

    public function handle(array $payload): string
    {
        $listingId = $this->id($payload, 'listing_id');
        $adapter   = service('marketplaces')->forListing($listingId);
        $before    = $adapter->findListing($listingId);
        $after     = $adapter->updateListingPrice($listingId, null, true);

        if (bccomp((string) $before['price'], (string) $after['price'], 2) === 0) {
            return sprintf('%s: preço já estava correto (R$ %s).', $adapter->name(), number_format((float) $after['price'], 2, ',', '.'));
        }

        return sprintf(
            '%s: preço enviado, R$ %s → R$ %s.',
            $adapter->name(),
            number_format((float) $before['price'], 2, ',', '.'),
            number_format((float) $after['price'], 2, ',', '.'),
        );
    }
}

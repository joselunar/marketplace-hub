<?php

namespace App\Jobs;

/**
 * Recalcula o preço com a tarifa oficial do Mercado Livre e envia ao anúncio.
 * Preço abaixo da margem mínima é recusado (o job falha sem nova tentativa).
 */
class SyncMercadoLivrePricesJob extends AbstractJob
{
    public const TYPE = 'meli_price';

    public static function label(): string
    {
        return 'Preço do anúncio';
    }

    public function handle(array $payload): string
    {
        $listingId = $this->id($payload, 'listing_id');
        $products  = service('mercadoLivreProducts');
        $before    = $products->findOrFail($listingId);
        $after     = $products->updatePrice($listingId, null, true);

        if (bccomp((string) $before['price'], (string) $after['price'], 2) === 0) {
            return 'Preço já estava correto (R$ ' . number_format((float) $after['price'], 2, ',', '.') . ').';
        }

        return sprintf(
            'Preço enviado: R$ %s → R$ %s.',
            number_format((float) $before['price'], 2, ',', '.'),
            number_format((float) $after['price'], 2, ',', '.'),
        );
    }
}

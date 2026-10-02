<?php

namespace App\Jobs;

class SyncMercadoLivreStockJob extends AbstractJob
{
    public const TYPE = 'meli_stock';

    public static function label(): string
    {
        return 'Estoque do anúncio';
    }

    public function handle(array $payload): string
    {
        $listingId = $this->id($payload, 'listing_id');
        $products  = service('mercadoLivreProducts');
        $before    = $products->findOrFail($listingId);
        $after     = $products->updateStock($listingId, null, true);

        if ($before['available_quantity'] !== null && (int) $before['available_quantity'] === (int) $after['available_quantity'] && $after['last_sync_at'] === $before['last_sync_at']) {
            return sprintf('Estoque já estava correto (%d).', (int) $after['available_quantity']);
        }

        return sprintf('Estoque enviado: %s → %d.', $before['available_quantity'] ?? '—', (int) $after['available_quantity']);
    }
}

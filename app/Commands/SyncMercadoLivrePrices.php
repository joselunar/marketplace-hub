<?php

namespace App\Commands;

use App\Jobs\SyncMercadoLivrePricesJob;
use CodeIgniter\CLI\BaseCommand;

/**
 * Recalcula o preço dos anúncios com a tarifa oficial e envia os que mudaram.
 * Preços abaixo da margem mínima nunca são enviados.
 */
class SyncMercadoLivrePrices extends BaseCommand
{
    use SyncsListings;

    protected $group       = 'Marketplace Hub';
    protected $name        = 'meli:sync-prices';
    protected $description = 'Recalcula com a tarifa oficial e envia o preço dos anúncios de produtos com preço válido.';
    protected $usage       = 'meli:sync-prices [--listing <id>] [--force] [--queue]';
    protected $options     = [
        '--listing' => 'Atualiza somente o anúncio informado (ID interno).',
        '--force'   => 'Executa mesmo com a atualização automática de preço desligada.',
        '--queue'   => 'Só enfileira os jobs para o worker executar.',
    ];

    public function run(array $params)
    {
        return $this->syncListings(
            $params,
            'sync.auto_update_price',
            'A atualização automática de preço está desligada (Configurações > Sincronização). Use --force para executar mesmo assim.',
            SyncMercadoLivrePricesJob::TYPE,
            static fn (): array => service('listingAutoSync')->priceCandidates(),
            static function (int $listingId): string {
                $listing = service('mercadoLivreProducts')->updatePrice($listingId, null, true);

                return 'preço R$ ' . number_format((float) $listing['price'], 2, ',', '.') . '.';
            },
        );
    }
}

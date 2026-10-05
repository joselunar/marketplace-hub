<?php

namespace App\Commands;

use App\Jobs\SyncListingPriceJob;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreAuthService;
use CodeIgniter\CLI\BaseCommand;

/**
 * Recalcula o preço dos anúncios com a tarifa oficial e envia os que mudaram.
 * Preços abaixo da margem mínima nunca são enviados.
 */
class SyncMercadoLivrePrices extends BaseCommand
{
    use SyncsListings;

    protected $group       = 'Vitrine Hub';
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
            SyncListingPriceJob::TYPE,
            static fn (): array => service('listingAutoSync')->priceCandidates(null, MercadoLivreAuthService::SLUG),
            static function (int $listingId): string {
                $listing = service('marketplaces')->get(MercadoLivreAuthService::SLUG)->updateListingPrice($listingId, null, true);

                return 'preço R$ ' . number_format((float) $listing['price'], 2, ',', '.') . '.';
            },
        );
    }
}

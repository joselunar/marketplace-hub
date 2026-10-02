<?php

namespace App\Commands;

use App\Jobs\SyncMercadoLivreStockJob;
use CodeIgniter\CLI\BaseCommand;

/**
 * Envia aos anúncios o estoque seguro do fornecedor principal, só onde está diferente.
 */
class SyncMercadoLivreStock extends BaseCommand
{
    use SyncsListings;

    protected $group       = 'Marketplace Hub';
    protected $name        = 'meli:sync-stock';
    protected $description = 'Envia o estoque do fornecedor principal aos anúncios que estão com quantidade diferente.';
    protected $usage       = 'meli:sync-stock [--listing <id>] [--force] [--queue]';
    protected $options     = [
        '--listing' => 'Atualiza somente o anúncio informado (ID interno).',
        '--force'   => 'Executa mesmo com a atualização automática de estoque desligada.',
        '--queue'   => 'Só enfileira os jobs para o worker executar.',
    ];

    public function run(array $params)
    {
        return $this->syncListings(
            $params,
            'sync.auto_update_stock',
            'A atualização automática de estoque está desligada (Configurações > Sincronização). Use --force para executar mesmo assim.',
            SyncMercadoLivreStockJob::TYPE,
            static fn (): array => service('listingAutoSync')->stockCandidates(),
            static function (int $listingId): string {
                $listing = service('mercadoLivreProducts')->updateStock($listingId, null, true);

                return 'quantidade ' . (int) $listing['available_quantity'] . '.';
            },
        );
    }
}

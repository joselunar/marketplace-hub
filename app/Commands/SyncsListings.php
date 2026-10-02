<?php

namespace App\Commands;

use App\Exceptions\BusinessRuleException;
use App\Models\JobModel;
use Closure;
use CodeIgniter\CLI\CLI;

/**
 * Parte comum de meli:sync-stock e meli:sync-prices.
 */
trait SyncsListings
{
    /**
     * @param Closure(): list<array{id: int, external_listing_id: string}> $candidates
     * @param Closure(int): string                                         $update
     */
    private function syncListings(array $params, string $setting, string $disabledMessage, string $jobType, Closure $candidates, Closure $update): int
    {
        $listingId = $params['listing'] ?? CLI::getOption('listing');
        $force     = array_key_exists('force', $params) || CLI::getOption('force') !== null;
        $queue     = array_key_exists('queue', $params) || CLI::getOption('queue') !== null;

        if ($listingId !== null && ! ctype_digit((string) $listingId)) {
            CLI::error('Informe um ID de anúncio válido.');

            return EXIT_USER_INPUT;
        }
        if ($listingId === null && ! $force && ! service('settings')->getBool($setting)) {
            CLI::write($disabledMessage, 'yellow');

            return EXIT_SUCCESS;
        }

        $listings = $listingId !== null ? [['id' => (int) $listingId, 'external_listing_id' => '#' . $listingId]] : $candidates();

        if ($listings === []) {
            CLI::write('Nenhum anúncio precisa de atualização.', 'green');

            return EXIT_SUCCESS;
        }

        $failures = 0;
        foreach ($listings as $listing) {
            if ($queue) {
                $id = service('jobQueue')->enqueue($jobType, ['listing_id' => $listing['id']], JobModel::SOURCE_COMMAND, $listing['external_listing_id']);
                CLI::write(sprintf('%s: %s', $listing['external_listing_id'], $id === null ? 'já estava na fila.' : 'job #' . $id . ' enfileirado.'), 'green');

                continue;
            }

            try {
                CLI::write(sprintf('%s: %s', $listing['external_listing_id'], $update($listing['id'])), 'green');
            } catch (BusinessRuleException $e) {
                $failures++;
                CLI::error(sprintf('%s: %s', $listing['external_listing_id'], $e->getMessage()));
            }
        }

        if (! $queue) {
            CLI::write(sprintf('%d anúncio(s) processado(s), %d falha(s).', count($listings), $failures), $failures > 0 ? 'yellow' : 'green');
        }

        return $failures > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}

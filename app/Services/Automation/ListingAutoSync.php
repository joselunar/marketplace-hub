<?php

namespace App\Services\Automation;

use App\Jobs\SyncMercadoLivrePricesJob;
use App\Jobs\SyncMercadoLivreStockJob;
use App\Models\JobModel;
use App\Models\MarketplaceAccountModel;
use App\Models\MarketplaceListingModel as Listings;
use App\Services\Settings\SettingsService;
use App\Services\Suppliers\SupplierSelectionService;
use CodeIgniter\Database\BaseBuilder;
use Config\Database;

/**
 * Atualização automática (opcional) de estoque e preço dos anúncios.
 *
 * Desligada por padrão. Ligada, toda mudança de fornecedor principal,
 * custo ou estoque enfileira o envio para os anúncios afetados, e o
 * agendador confere periodicamente os que ficaram diferentes.
 */
class ListingAutoSync
{
    /**
     * Só anúncios publicados e vivos recebem atualização.
     */
    private const SYNCABLE_STATUSES = [Listings::STATUS_ACTIVE, Listings::STATUS_PAUSED];

    private const MAX_CANDIDATES = 2000;

    public function __construct(
        private readonly ?SettingsService $settings = null,
        private readonly ?JobQueue $queue = null,
    ) {
    }

    public function stockEnabled(): bool
    {
        return $this->settings()->getBool('sync.auto_update_stock');
    }

    public function priceEnabled(): bool
    {
        return $this->settings()->getBool('sync.auto_update_price');
    }

    /**
     * Chamado pela seleção de fornecedores depois de gravar um produto.
     *
     * @return int jobs enfileirados
     */
    public function afterSelection(int $productId, bool $priceChanged): int
    {
        $count = 0;

        if ($this->stockEnabled()) {
            $count += $this->enqueue(SyncMercadoLivreStockJob::TYPE, $this->stockCandidates($productId), JobModel::SOURCE_EVENT);
        }
        if ($priceChanged && $this->priceEnabled()) {
            $count += $this->enqueue(SyncMercadoLivrePricesJob::TYPE, $this->priceCandidates($productId), JobModel::SOURCE_EVENT);
        }

        return $count;
    }

    /**
     * @return int jobs enfileirados
     */
    public function enqueueStock(string $source, ?int $userId = null): int
    {
        return $this->enqueue(SyncMercadoLivreStockJob::TYPE, $this->stockCandidates(), $source, $userId);
    }

    /**
     * @return int jobs enfileirados
     */
    public function enqueuePrices(string $source, ?int $userId = null): int
    {
        return $this->enqueue(SyncMercadoLivrePricesJob::TYPE, $this->priceCandidates(), $source, $userId);
    }

    /**
     * Anúncios cuja quantidade gravada difere do estoque seguro do
     * fornecedor principal (zero quando o produto está sem fornecedor).
     *
     * @return list<array{id: int, external_listing_id: string}>
     */
    public function stockCandidates(?int $productId = null): array
    {
        $builder = $this->baseQuery($productId)
            ->join('supplier_offers so', 'so.id = p.selected_offer_id', 'left')
            ->groupStart()
                ->where('ml.available_quantity IS NULL', null, false)
                ->orWhere('ml.available_quantity <> GREATEST(COALESCE(so.available_stock, 0), 0)', null, false)
            ->groupEnd();

        return $this->rows($builder);
    }

    /**
     * Anúncios de produtos com preço calculado e dentro da margem. O preço
     * final depende da tarifa oficial, consultada pelo próprio job.
     *
     * @return list<array{id: int, external_listing_id: string}>
     */
    public function priceCandidates(?int $productId = null): array
    {
        return $this->rows($this->baseQuery($productId)->where('p.price_status', SupplierSelectionService::PRICE_STATUS_OK));
    }

    private function baseQuery(?int $productId): BaseBuilder
    {
        $builder = Database::connect()->table('marketplace_listings ml')
            ->select('ml.id, ml.external_listing_id')
            ->join('products p', 'p.id = ml.product_id')
            ->join('marketplace_accounts a', 'a.id = ml.marketplace_account_id')
            ->where('a.status', MarketplaceAccountModel::STATUS_CONNECTED)
            ->where('a.active', 1)
            ->where('ml.external_listing_id IS NOT NULL', null, false)
            ->whereIn('ml.status', self::SYNCABLE_STATUSES)
            ->orderBy('ml.id')
            ->limit(self::MAX_CANDIDATES);

        if ($productId !== null) {
            $builder->where('ml.product_id', $productId);
        }

        return $builder;
    }

    /**
     * @return list<array{id: int, external_listing_id: string}>
     */
    private function rows(BaseBuilder $builder): array
    {
        return array_map(static fn (array $row): array => [
            'id'                  => (int) $row['id'],
            'external_listing_id' => (string) $row['external_listing_id'],
        ], $builder->get()->getResultArray());
    }

    /**
     * @param list<array{id: int, external_listing_id: string}> $listings
     */
    private function enqueue(string $type, array $listings, string $source, ?int $userId = null): int
    {
        $count = 0;
        foreach ($listings as $listing) {
            if ($this->queue()->enqueue($type, ['listing_id' => $listing['id']], $source, $listing['external_listing_id'], $userId) !== null) {
                $count++;
            }
        }

        return $count;
    }

    private function settings(): SettingsService
    {
        return $this->settings ?? service('settings');
    }

    private function queue(): JobQueue
    {
        return $this->queue ?? service('jobQueue');
    }
}

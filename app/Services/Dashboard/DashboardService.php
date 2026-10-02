<?php

namespace App\Services\Dashboard;

use App\Models\ProductModel;
use App\Models\SupplierModel;
use App\Models\SupplierOfferModel;
use App\Models\SupplierSelectionLogModel;
use App\Models\SyncLogModel;
use App\Services\Marketplaces\MarketplaceAccountService;

/**
 * Indicadores do dashboard. Cada métrica é uma consulta agregada simples;
 * nada é carregado em memória registro a registro.
 */
class DashboardService
{
    public function __construct(
        private readonly ProductModel $products = new ProductModel(),
        private readonly SupplierModel $suppliers = new SupplierModel(),
        private readonly SupplierOfferModel $offers = new SupplierOfferModel(),
        private readonly SyncLogModel $syncLogs = new SyncLogModel(),
        private readonly SupplierSelectionLogModel $selectionLogs = new SupplierSelectionLogModel(),
        private readonly MarketplaceAccountService $marketplaceAccounts = new MarketplaceAccountService(),
    ) {
    }

    public function summary(): array
    {
        return [
            'activeProducts'       => $this->products->where('active', 1)->countAllResults(),
            'productsWithoutOffer' => $this->products->applyFilters(['status' => 'active', 'supplier' => 'without'])->countAllResults(),
            'productsOutOfStock'   => $this->products->applyFilters(['status' => 'active', 'stock' => 'out'])->countAllResults(),
            'productsWithoutEligibleSupplier' => $this->products->applyFilters(['status' => 'active', 'selection' => 'none'])->countAllResults(),
            'productsBelowMinimumMargin' => $this->products->applyFilters(['status' => 'active', 'pricing' => 'below_minimum'])->countAllResults(),
            'productsWithInvalidPricing' => $this->products->applyFilters(['status' => 'active', 'pricing' => 'invalid'])->countAllResults(),
            'activeSuppliers'      => $this->suppliers->where('status', 'active')->countAllResults(),
            'recentSupplierChanges' => $this->selectionLogs
                ->select('supplier_selection_logs.*, products.sku AS product_sku')
                ->join('products', 'products.id = supplier_selection_logs.product_id')
                ->orderBy('supplier_selection_logs.id', 'DESC')
                ->findAll(5),
            'unlinkedOffers'       => $this->offers->where('product_id', null)->countAllResults(),
            'marketplaceAccounts'  => $this->marketplaceAccounts->attentionSummary(),
            'recentSyncErrors'     => $this->syncLogs
                ->whereIn('status', [SyncLogModel::STATUS_ERROR, SyncLogModel::STATUS_WARNING])
                ->where('started_at >=', date('Y-m-d H:i:s', strtotime('-7 days')))
                ->orderBy('started_at', 'DESC')
                ->findAll(5),
        ];
    }
}

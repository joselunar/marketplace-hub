<?php

namespace App\Services\Dashboard;

use App\Models\OrderModel;
use App\Models\ProductModel;
use App\Models\SupplierModel;
use App\Models\SupplierOfferModel;
use App\Models\SupplierSelectionLogModel;
use App\Models\SyncLogModel;
use App\Models\WebhookEventModel;
use App\Services\Marketplaces\MarketplaceAccountService;
use App\Services\Orders\OrderProfitCalculator;

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
        private readonly OrderModel $orders = new OrderModel(),
        private readonly WebhookEventModel $webhookEvents = new WebhookEventModel(),
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
            'orders'               => $this->ordersSummary(),
        ];
    }

    /**
     * Pedidos de hoje e dos últimos 7 dias. Faturamento e lucro somam só as
     * vendas não canceladas; a margem considera apenas pedidos com lucro
     * calculado (todos os itens com fornecedor reservado).
     */
    private function ordersSummary(): array
    {
        $since = date('Y-m-d H:i:s', strtotime('-7 days'));
        $row   = $this->orders->builder()
            ->select('COUNT(*) AS orders_7d')
            ->select('SUM(CASE WHEN ordered_at >= ' . $this->orders->db->escape(date('Y-m-d 00:00:00')) . ' THEN 1 ELSE 0 END) AS orders_today', false)
            ->select('COALESCE(SUM(total_amount), 0) AS revenue', false)
            ->select('COALESCE(SUM(estimated_profit), 0) AS profit', false)
            ->select('COALESCE(SUM(CASE WHEN estimated_profit IS NOT NULL THEN total_amount END), 0) AS profit_base', false)
            ->where('ordered_at >=', $since)
            ->whereNotIn('status', OrderModel::CANCELLED_STATUSES)
            ->get()
            ->getRowArray();

        return [
            'today'            => (int) ($row['orders_today'] ?? 0),
            'last7Days'        => (int) ($row['orders_7d'] ?? 0),
            'revenue'          => (string) ($row['revenue'] ?? '0.00'),
            'profit'           => (string) ($row['profit'] ?? '0.00'),
            'margin'           => (new OrderProfitCalculator())->margin((string) ($row['profit'] ?? '0'), (string) ($row['profit_base'] ?? '0')),
            'awaitingSupplier' => $this->orders->whereIn('fulfillment_status', OrderModel::AWAITING_SUPPLIER)->countAllResults(),
            'needsAttention'   => $this->orders->where('fulfillment_status', OrderModel::FULFILLMENT_ATTENTION)->countAllResults(),
            'failedNotifications' => $this->webhookEvents->where('status', WebhookEventModel::STATUS_FAILED)->where('received_at >=', $since)->countAllResults(),
        ];
    }
}

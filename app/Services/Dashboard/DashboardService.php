<?php

namespace App\Services\Dashboard;

use App\Models\JobModel;
use App\Models\MarketplaceListingModel;
use App\Models\OrderModel;
use App\Models\ProductModel;
use App\Models\SupplierModel;
use App\Models\SupplierOfferModel;
use App\Models\SupplierSelectionLogModel;
use App\Models\SyncLogModel;
use App\Models\WebhookEventModel;
use App\Services\Automation\JobQueue;
use App\Services\Marketplaces\MarketplaceAccountService;
use App\Services\Orders\OrderProfitCalculator;

/**
 * Indicadores do dashboard. Cada métrica é uma consulta agregada simples;
 * nada é carregado em memória registro a registro.
 */
class DashboardService
{
    private const CHART_DAYS    = 14;
    private const RECENT_ORDERS = 5;
    private const TOP_PRODUCTS  = 5;
    private const TOP_DAYS      = 30;

    public function __construct(
        private readonly ProductModel $products = new ProductModel(),
        private readonly SupplierModel $suppliers = new SupplierModel(),
        private readonly SupplierOfferModel $offers = new SupplierOfferModel(),
        private readonly SyncLogModel $syncLogs = new SyncLogModel(),
        private readonly SupplierSelectionLogModel $selectionLogs = new SupplierSelectionLogModel(),
        private readonly MarketplaceAccountService $marketplaceAccounts = new MarketplaceAccountService(),
        private readonly OrderModel $orders = new OrderModel(),
        private readonly WebhookEventModel $webhookEvents = new WebhookEventModel(),
        private readonly ?JobQueue $queue = null,
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
            'listings'             => $this->listingsSummary(),
            'automation'           => $this->automationSummary(),
            'salesChart'           => $this->salesChart(),
            'recentOrders'         => $this->orders
                ->select('orders.id, orders.external_order_id, orders.status, orders.fulfillment_status, orders.total_amount, orders.estimated_profit, orders.ordered_at')
                ->orderBy('orders.ordered_at', 'DESC')
                ->findAll(self::RECENT_ORDERS),
            'topProducts'          => $this->topProducts(),
        ];
    }

    /**
     * Anúncios ativos e os que precisam de atenção (publicação recusada ou
     * última atualização de preço/estoque com erro).
     */
    private function listingsSummary(): array
    {
        $listings = new MarketplaceListingModel();

        return [
            'active' => $listings->where('status', MarketplaceListingModel::STATUS_ACTIVE)->countAllResults(),
            'paused' => $listings->where('status', MarketplaceListingModel::STATUS_PAUSED)->countAllResults(),
            'errors' => $listings->groupStart()
                ->where('status', MarketplaceListingModel::STATUS_ERROR)
                ->orGroupStart()
                    ->where('last_error IS NOT NULL', null, false)
                    ->whereIn('status', [MarketplaceListingModel::STATUS_ACTIVE, MarketplaceListingModel::STATUS_PAUSED])
                ->groupEnd()
                ->groupEnd()
                ->countAllResults(),
        ];
    }

    /**
     * Fila de jobs e situação do cron.
     */
    private function automationSummary(): array
    {
        $queue  = $this->queue ?? service('jobQueue');
        $counts = $queue->counts();

        return [
            'failedJobs'  => $counts[JobModel::STATUS_FAILED],
            'pendingJobs' => $counts[JobModel::STATUS_PENDING],
            'heartbeat'   => $queue->heartbeat(),
        ];
    }

    /**
     * Pedidos e faturamento por dia, sem os cancelados, com os dias sem venda zerados.
     *
     * @return list<array{date: string, orders: int, revenue: string}>
     */
    private function salesChart(): array
    {
        $start = date('Y-m-d', strtotime('-' . (self::CHART_DAYS - 1) . ' days'));
        $rows  = $this->orders->builder()
            ->select('DATE(ordered_at) AS day, COUNT(*) AS orders, COALESCE(SUM(total_amount), 0) AS revenue', false)
            ->where('ordered_at >=', $start . ' 00:00:00')
            ->whereNotIn('status', OrderModel::CANCELLED_STATUSES)
            ->groupBy('day')
            ->get()
            ->getResultArray();
        $byDay = array_column($rows, null, 'day');

        $chart = [];
        for ($i = 0; $i < self::CHART_DAYS; $i++) {
            $day     = date('Y-m-d', strtotime($start . ' +' . $i . ' days'));
            $chart[] = [
                'date'    => $day,
                'orders'  => (int) ($byDay[$day]['orders'] ?? 0),
                'revenue' => (string) ($byDay[$day]['revenue'] ?? '0.00'),
            ];
        }

        return $chart;
    }

    /**
     * Produtos com maior lucro estimado nos pedidos dos últimos 30 dias.
     */
    private function topProducts(): array
    {
        return $this->orders->db->table('order_items oi')
            ->select('p.id, p.sku, p.name, SUM(oi.quantity) AS quantity, SUM(oi.quantity * oi.unit_price) AS revenue, SUM(oi.estimated_profit) AS profit', false)
            ->join('orders o', 'o.id = oi.order_id')
            ->join('products p', 'p.id = oi.product_id')
            ->where('o.ordered_at >=', date('Y-m-d H:i:s', strtotime('-' . self::TOP_DAYS . ' days')))
            ->whereNotIn('o.status', OrderModel::CANCELLED_STATUSES)
            ->where('oi.estimated_profit IS NOT NULL', null, false)
            ->groupBy('p.id, p.sku, p.name')
            ->orderBy('profit', 'DESC')
            ->limit(self::TOP_PRODUCTS)
            ->get()
            ->getResultArray();
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

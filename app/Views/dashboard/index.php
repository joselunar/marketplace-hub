<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$cards = [
    ['label' => 'Produtos ativos', 'value' => $summary['activeProducts'], 'icon' => 'box-seam', 'color' => 'primary', 'url' => 'products?status=active'],
    ['label' => 'Produtos sem fornecedor', 'value' => $summary['productsWithoutOffer'], 'icon' => 'exclamation-diamond', 'color' => 'warning', 'url' => 'products?supplier=without'],
    ['label' => 'Produtos sem estoque', 'value' => $summary['productsOutOfStock'], 'icon' => 'box', 'color' => 'danger', 'url' => 'products?status=active&stock=out'],
    ['label' => 'Fornecedores ativos', 'value' => $summary['activeSuppliers'], 'icon' => 'truck', 'color' => 'success', 'url' => 'suppliers?status=active'],
    ['label' => 'Anúncios ativos', 'value' => $summary['listings']['active'], 'icon' => 'megaphone', 'color' => 'info', 'url' => 'marketplaces/mercadolivre/listings?status=active'],
];
?>
<div class="row g-3 mb-4">
    <?php foreach ($cards as $card): ?>
        <div class="col-12 col-sm-6 col-xl">
            <a href="<?= site_url($card['url']) ?>" class="card stat-card h-100 text-decoration-none text-body">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon bg-<?= $card['color'] ?>-subtle text-<?= $card['color'] ?>"><i class="bi bi-<?= $card['icon'] ?>"></i></span>
                    <div>
                        <div class="stat-value"><?= number_format($card['value'], 0, ',', '.') ?></div>
                        <div class="small text-body-secondary"><?= esc($card['label']) ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach ?>
</div>

<?php
$orders     = $summary['orders'];
$orderCards = [
    ['label' => 'Pedidos hoje', 'value' => number_format($orders['today'], 0, ',', '.'), 'icon' => 'cart-check', 'color' => 'primary', 'url' => 'orders?period=today'],
    ['label' => 'Pedidos nos últimos 7 dias', 'value' => number_format($orders['last7Days'], 0, ',', '.'), 'icon' => 'calendar-week', 'color' => 'primary', 'url' => 'orders?period=7d'],
    ['label' => 'Faturamento estimado (7 dias)', 'value' => money($orders['revenue']), 'icon' => 'cash-stack', 'color' => 'success', 'url' => 'orders?period=7d'],
    ['label' => 'Lucro estimado (7 dias)', 'value' => money($orders['profit']), 'icon' => 'graph-up-arrow', 'color' => 'success', 'url' => 'orders?period=7d'],
    ['label' => 'Margem média (7 dias)', 'value' => percent_format($orders['margin']), 'icon' => 'percent', 'color' => 'info', 'url' => 'orders?period=7d'],
];
?>
<div class="row g-3 mb-4">
    <?php foreach ($orderCards as $card): ?>
        <div class="col-12 col-sm-6 col-xl">
            <a href="<?= site_url($card['url']) ?>" class="card stat-card h-100 text-decoration-none text-body">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon bg-<?= $card['color'] ?>-subtle text-<?= $card['color'] ?>"><i class="bi bi-<?= $card['icon'] ?>"></i></span>
                    <div>
                        <div class="stat-value text-nowrap"><?= esc($card['value']) ?></div>
                        <div class="small text-body-secondary"><?= esc($card['label']) ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach ?>
</div>

<?php
$chart      = $summary['salesChart'];
$maxRevenue = max(array_map(static fn (array $day): float => (float) $day['revenue'], $chart)) ?: 1.0;
$chartTotal = array_sum(array_map(static fn (array $day): float => (float) $day['revenue'], $chart));
$automation = $summary['automation'];
$accounts   = $summary['marketplaceAccounts'];
?>
<div class="row g-3 mb-4">
    <div class="col-12 col-xl-7">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span><i class="bi bi-bar-chart me-1"></i> Vendas dos últimos <?= count($chart) ?> dias</span>
                <span class="small text-body-secondary fw-normal"><?= money((string) $chartTotal) ?> em <?= array_sum(array_column($chart, 'orders')) ?> pedido(s)</span>
            </div>
            <div class="card-body">
                <div class="sales-chart" role="img" aria-label="Faturamento por dia nos últimos <?= count($chart) ?> dias">
                    <?php foreach ($chart as $day): ?>
                        <?php $height = (float) $day['revenue'] > 0 ? max(4, (int) round((float) $day['revenue'] / $maxRevenue * 100)) : 0 ?>
                        <div class="sales-chart-col" title="<?= esc(date('d/m', strtotime($day['date'])) . ': ' . money($day['revenue']) . ' em ' . $day['orders'] . ' pedido(s)', 'attr') ?>">
                            <div class="sales-chart-bar-wrap"><div class="sales-chart-bar" style="height: <?= $height ?>%"></div></div>
                            <div class="sales-chart-label"><?= date('d/m', strtotime($day['date'])) ?></div>
                        </div>
                    <?php endforeach ?>
                </div>
                <?php if ($chartTotal == 0): ?>
                    <div class="small text-body-secondary text-center mt-2">Nenhuma venda no período.</div>
                <?php endif ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-5">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-trophy me-1"></i> Produtos com maior lucro (30 dias)</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($summary['topProducts'] as $product): ?>
                    <li class="list-group-item small d-flex justify-content-between align-items-center gap-2">
                        <span class="text-truncate">
                            <a href="<?= site_url('products/' . (int) $product['id']) ?>" class="text-decoration-none fw-medium"><?= esc($product['sku']) ?></a>
                            <span class="text-body-secondary"><?= esc($product['name']) ?></span>
                        </span>
                        <span class="text-nowrap text-end">
                            <strong class="text-success"><?= money($product['profit']) ?></strong>
                            <div class="text-body-secondary"><?= (int) $product['quantity'] ?> un. · <?= money($product['revenue']) ?></div>
                        </span>
                    </li>
                <?php endforeach ?>
                <?php if ($summary['topProducts'] === []): ?>
                    <li class="list-group-item small text-body-secondary">Sem vendas com lucro calculado no período.</li>
                <?php endif ?>
            </ul>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-7">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-bell me-1"></i> Alertas</div>
            <ul class="list-group list-group-flush">
                <?php if ($automation['heartbeat']['scheduler_late']): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-clock-history text-danger me-2"></i><?= $automation['heartbeat']['scheduler'] === null
                            ? 'O agendador nunca rodou: configure o cron de <code>schedule:run</code> e <code>jobs:work</code>'
                            : 'O agendador não roda desde ' . format_datetime($automation['heartbeat']['scheduler']['last_run_at']) . ': sincronizações e renovação de tokens estão paradas' ?></span>
                        <a href="<?= site_url('sync') ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </li>
                <?php endif ?>
                <?php if ($automation['failedJobs'] > 0): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-arrow-repeat text-danger me-2"></i><?= $automation['failedJobs'] ?> job(s) de sincronização falharam após as novas tentativas</span>
                        <a href="<?= site_url('sync?status=failed') ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </li>
                <?php endif ?>
                <?php if ($summary['listings']['errors'] > 0): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-megaphone text-danger me-2"></i><?= $summary['listings']['errors'] ?> anúncio(s) com erro na publicação ou na última atualização</span>
                        <a href="<?= site_url('marketplaces/mercadolivre/listings') ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </li>
                <?php endif ?>
                <?php if ($accounts['expiring'] > 0): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-hourglass-split text-warning me-2"></i><?= $accounts['expiring'] ?> conta(s) de marketplace com token vencendo nos próximos minutos sem renovação</span>
                        <a href="<?= site_url('marketplaces') ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </li>
                <?php endif ?>
                <?php if ($orders['awaitingSupplier'] > 0): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span>
                            <i class="bi bi-cart-x text-danger me-2"></i><?= $orders['awaitingSupplier'] ?> pedido(s) aguardando fornecedor
                            <?php if ($orders['needsAttention'] > 0): ?><span class="small text-body-secondary">(<?= $orders['needsAttention'] ?> com pendência)</span><?php endif ?>
                        </span>
                        <a href="<?= site_url('orders?fulfillment=awaiting_supplier') ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </li>
                <?php endif ?>
                <?php if ($orders['failedNotifications'] > 0): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-bell-slash text-danger me-2"></i><?= $orders['failedNotifications'] ?> notificação(ões) do Mercado Livre com falha nos últimos 7 dias</span>
                        <a href="<?= site_url('marketplaces/mercadolivre/notifications?status=failed') ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </li>
                <?php endif ?>
                <?php if ($summary['productsWithoutOffer'] > 0): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-exclamation-triangle text-warning me-2"></i><?= $summary['productsWithoutOffer'] ?> produto(s) ativo(s) sem nenhuma oferta de fornecedor</span>
                        <a href="<?= site_url('products?supplier=without') ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </li>
                <?php endif ?>
                <?php if ($summary['productsWithoutEligibleSupplier'] > 0): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-x-octagon text-danger me-2"></i><?= $summary['productsWithoutEligibleSupplier'] ?> produto(s) ativo(s) sem fornecedor disponível (nenhuma oferta elegível)</span>
                        <a href="<?= site_url('products?status=active&selection=none') ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </li>
                <?php endif ?>
                <?php if ($summary['productsBelowMinimumMargin'] > 0): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-graph-down-arrow text-danger me-2"></i><?= $summary['productsBelowMinimumMargin'] ?> produto(s) ativo(s) com margem abaixo da mínima no preço fixo</span>
                        <a href="<?= site_url('products?status=active&pricing=below_minimum') ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </li>
                <?php endif ?>
                <?php if ($summary['productsWithInvalidPricing'] > 0): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-calculator text-danger me-2"></i><?= $summary['productsWithInvalidPricing'] ?> produto(s) ativo(s) sem preço possível (taxas e margem somam 100% ou mais)</span>
                        <a href="<?= site_url('products?status=active&pricing=invalid') ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </li>
                <?php endif ?>
                <?php if ($summary['unlinkedOffers'] > 0): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-link-45deg text-info me-2"></i><?= $summary['unlinkedOffers'] ?> oferta(s) importada(s) sem vínculo com o catálogo</span>
                        <a href="<?= site_url('offers?link=unlinked') ?>" class="btn btn-sm btn-outline-secondary">Vincular</a>
                    </li>
                <?php endif ?>
                <?php if ($summary['marketplaceAccounts']['reconnect'] > 0): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-plug text-danger me-2"></i><?= $summary['marketplaceAccounts']['reconnect'] ?> conta(s) de marketplace precisam ser reconectadas (autorização revogada ou expirada)</span>
                        <a href="<?= site_url('marketplaces') ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </li>
                <?php endif ?>
                <?php if ($summary['marketplaceAccounts']['expired'] > 0): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-hourglass-bottom text-warning me-2"></i><?= $summary['marketplaceAccounts']['expired'] ?> conta(s) de marketplace com token vencido (confira o cron do agendador)</span>
                        <a href="<?= site_url('marketplaces') ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </li>
                <?php endif ?>
                <?php foreach ($summary['recentSyncErrors'] as $log): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <span><?= sync_status_badge($log['status']) ?> <span class="small"><?= esc($log['action']) ?></span></span>
                            <span class="small text-body-secondary"><?= format_datetime($log['started_at']) ?></span>
                        </div>
                        <div class="small text-body-secondary mt-1"><?= esc($log['message']) ?></div>
                    </li>
                <?php endforeach ?>
                <?php if ($summary['productsWithoutOffer'] === 0 && $summary['productsWithoutEligibleSupplier'] === 0 && $summary['productsBelowMinimumMargin'] === 0
                    && $summary['productsWithInvalidPricing'] === 0 && $summary['unlinkedOffers'] === 0 && $summary['recentSyncErrors'] === []
                    && $accounts['reconnect'] === 0 && $accounts['expired'] === 0 && $accounts['expiring'] === 0
                    && $orders['awaitingSupplier'] === 0 && $orders['failedNotifications'] === 0
                    && ! $automation['heartbeat']['scheduler_late'] && $automation['failedJobs'] === 0 && $summary['listings']['errors'] === 0): ?>
                    <li class="list-group-item text-body-secondary small"><i class="bi bi-check-circle text-success me-1"></i> Nenhum alerta no momento.</li>
                <?php endif ?>
            </ul>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span><i class="bi bi-cart me-1"></i> Pedidos recentes</span>
                <a href="<?= site_url('orders') ?>" class="small fw-normal">Ver todos</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($summary['recentOrders'] as $order): ?>
                    <li class="list-group-item small d-flex justify-content-between align-items-center gap-2">
                        <span>
                            <a href="<?= site_url('orders/' . (int) $order['id']) ?>" class="text-decoration-none fw-medium"><?= esc($order['external_order_id']) ?></a>
                            <div class="text-body-secondary"><?= format_datetime($order['ordered_at']) ?></div>
                        </span>
                        <span class="text-end">
                            <?= money($order['total_amount']) ?>
                            <div><?= order_status_badge($order['status']) ?> <?= fulfillment_badge($order['fulfillment_status']) ?></div>
                        </span>
                    </li>
                <?php endforeach ?>
                <?php if ($summary['recentOrders'] === []): ?>
                    <li class="list-group-item small text-body-secondary">Nenhum pedido importado ainda.</li>
                <?php endif ?>
            </ul>
        </div>
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-arrow-left-right me-1"></i> Trocas recentes de fornecedor</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($summary['recentSupplierChanges'] as $change): ?>
                    <li class="list-group-item small">
                        <div class="d-flex justify-content-between">
                            <a href="<?= site_url('products/' . $change['product_id']) ?>" class="text-decoration-none fw-medium"><?= esc($change['product_sku']) ?></a>
                            <span class="text-body-secondary"><?= format_datetime($change['created_at']) ?></span>
                        </div>
                        <div><?= esc($change['previous_supplier_name'] ?? '—') ?> <i class="bi bi-arrow-right"></i> <strong><?= esc($change['new_supplier_name'] ?? 'Nenhum') ?></strong></div>
                        <div class="text-body-secondary"><?= esc($change['reason']) ?></div>
                    </li>
                <?php endforeach ?>
                <?php if ($summary['recentSupplierChanges'] === []): ?>
                    <li class="list-group-item small text-body-secondary">Nenhuma troca registrada.</li>
                <?php endif ?>
            </ul>
        </div>
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-shop me-1"></i> Marketplaces</span>
                <a href="<?= site_url('marketplaces') ?>" class="small">Ver todos</a>
            </div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($marketplaceAdapters as $adapter): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <a href="<?= site_url($adapter->accountsPath()) ?>" class="text-decoration-none"><?= esc($adapter->name()) ?></a>
                        <?= $adapter->isConfigured() ? badge('Configurado', 'success') : badge('Não configurado', 'warning') ?>
                    </li>
                <?php endforeach ?>
            </ul>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

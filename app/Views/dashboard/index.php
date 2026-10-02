<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$cards = [
    ['label' => 'Produtos ativos', 'value' => $summary['activeProducts'], 'icon' => 'box-seam', 'color' => 'primary', 'url' => 'products?status=active'],
    ['label' => 'Produtos sem fornecedor', 'value' => $summary['productsWithoutOffer'], 'icon' => 'exclamation-diamond', 'color' => 'warning', 'url' => 'products?supplier=without'],
    ['label' => 'Produtos sem estoque', 'value' => $summary['productsOutOfStock'], 'icon' => 'box', 'color' => 'danger', 'url' => 'products?status=active&stock=out'],
    ['label' => 'Fornecedores ativos', 'value' => $summary['activeSuppliers'], 'icon' => 'truck', 'color' => 'success', 'url' => 'suppliers?status=active'],
];
?>
<div class="row g-3 mb-4">
    <?php foreach ($cards as $card): ?>
        <div class="col-12 col-sm-6 col-xl-3">
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

<div class="row g-3">
    <div class="col-12 col-xl-7">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-bell me-1"></i> Alertas</div>
            <ul class="list-group list-group-flush">
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
                        <span><i class="bi bi-plug text-danger me-2"></i><?= $summary['marketplaceAccounts']['reconnect'] ?> conta(s) do Mercado Livre precisam ser reconectadas (autorização revogada ou expirada)</span>
                        <a href="<?= site_url('marketplaces/mercadolivre') ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </li>
                <?php endif ?>
                <?php if ($summary['marketplaceAccounts']['expired'] > 0): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-hourglass-bottom text-warning me-2"></i><?= $summary['marketplaceAccounts']['expired'] ?> conta(s) do Mercado Livre com token vencido (confira o cron do <code>meli:refresh-token</code>)</span>
                        <a href="<?= site_url('marketplaces/mercadolivre') ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
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
                    && $summary['marketplaceAccounts']['reconnect'] === 0 && $summary['marketplaceAccounts']['expired'] === 0): ?>
                    <li class="list-group-item text-body-secondary small"><i class="bi bi-check-circle text-success me-1"></i> Nenhum alerta no momento.</li>
                <?php endif ?>
            </ul>
        </div>
    </div>

    <div class="col-12 col-xl-5">
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
            <div class="card-header bg-white fw-semibold"><i class="bi bi-signpost-split me-1"></i> Próximos módulos</div>
            <div class="card-body small text-body-secondary">
                <p class="mb-2">Indicadores de anúncios, pedidos, faturamento, lucro e margem serão exibidos aqui conforme os módulos forem entregues:</p>
                <ul class="mb-0">
                    <li>Mercado Livre: anúncios e pedidos (Fases 6 e 7)</li>
                    <li>Jobs de sincronização e dashboard completo (Fase 8)</li>
                </ul>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

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
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-bell me-1"></i> Alertas</div>
            <ul class="list-group list-group-flush">
                <?php if ($summary['productsWithoutOffer'] > 0): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-exclamation-triangle text-warning me-2"></i><?= $summary['productsWithoutOffer'] ?> produto(s) ativo(s) sem nenhuma oferta de fornecedor</span>
                        <a href="<?= site_url('products?supplier=without') ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </li>
                <?php endif ?>
                <?php if ($summary['unlinkedOffers'] > 0): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-link-45deg text-info me-2"></i><?= $summary['unlinkedOffers'] ?> oferta(s) importada(s) sem vínculo com o catálogo</span>
                        <a href="<?= site_url('offers?link=unlinked') ?>" class="btn btn-sm btn-outline-secondary">Vincular</a>
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
                <?php if ($summary['productsWithoutOffer'] === 0 && $summary['unlinkedOffers'] === 0 && $summary['recentSyncErrors'] === []): ?>
                    <li class="list-group-item text-body-secondary small"><i class="bi bi-check-circle text-success me-1"></i> Nenhum alerta no momento.</li>
                <?php endif ?>
            </ul>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-signpost-split me-1"></i> Próximos módulos</div>
            <div class="card-body small text-body-secondary">
                <p class="mb-2">Indicadores de anúncios, pedidos, faturamento, lucro e margem serão exibidos aqui conforme os módulos forem entregues:</p>
                <ul class="mb-0">
                    <li>Motor de seleção de fornecedores e estoque de segurança (Fase 3)</li>
                    <li>Precificação e margens (Fase 4)</li>
                    <li>Mercado Livre: conexão OAuth, anúncios e pedidos (Fases 5 a 7)</li>
                    <li>Jobs de sincronização e dashboard completo (Fase 8)</li>
                </ul>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

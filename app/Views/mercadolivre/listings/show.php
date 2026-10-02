<?php

use App\Models\MarketplaceListingEventModel;
use App\Models\MarketplaceListingModel;

?>
<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?= $this->include('mercadolivre/_tabs') ?>

<?php
$published = ! empty($listing['external_listing_id']);
$base      = 'marketplaces/mercadolivre/listings/' . $listing['id'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h2 class="h5 mb-1"><?= esc($listing['title'] ?? $listing['family_name'] ?? 'Anúncio') ?></h2>
        <div class="small text-body-secondary">
            <?= listing_status_badge($listing['status']) ?>
            <?= badge(MarketplaceListingModel::MODEL_LABELS[$listing['selling_model']] ?? $listing['selling_model'], $listing['selling_model'] === MarketplaceListingModel::MODEL_USER_PRODUCTS ? 'info' : 'secondary') ?>
            · Conta <?= esc($listing['account_name']) ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($published): ?>
            <?php if ($listing['permalink']): ?>
                <a href="<?= esc($listing['permalink'], 'attr') ?>" class="btn btn-sm btn-light" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i> Ver no Mercado Livre</a>
            <?php endif ?>
            <form action="<?= site_url($base . '/sync') ?>" method="post"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> Sincronizar</button></form>
            <form action="<?= site_url($base . '/price') ?>" method="post" data-confirm="Recalcular o preço com a tarifa oficial e o fornecedor atual e enviar ao Mercado Livre?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-currency-dollar"></i> Atualizar preço</button></form>
            <form action="<?= site_url($base . '/stock') ?>" method="post" data-confirm="Enviar o estoque seguro do fornecedor principal ao Mercado Livre?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-seam"></i> Atualizar estoque</button></form>
            <?php if ($listing['status'] === MarketplaceListingModel::STATUS_ACTIVE): ?>
                <form action="<?= site_url($base . '/pause') ?>" method="post" data-confirm="Pausar o anúncio <?= esc($listing['external_listing_id'], 'attr') ?>?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-warning"><i class="bi bi-pause-fill"></i> Pausar</button></form>
            <?php elseif ($listing['status'] === MarketplaceListingModel::STATUS_PAUSED): ?>
                <form action="<?= site_url($base . '/activate') ?>" method="post"><?= csrf_field() ?><button class="btn btn-sm btn-outline-success"><i class="bi bi-play-fill"></i> Reativar</button></form>
            <?php endif ?>
        <?php elseif ($listing['product_id'] !== null && in_array($listing['status'], [MarketplaceListingModel::STATUS_ERROR, MarketplaceListingModel::STATUS_CLOSED], true)): ?>
            <a href="<?= site_url('marketplaces/mercadolivre/listings/new?product_id=' . $listing['product_id'] . '&account=' . $listing['marketplace_account_id']) ?>" class="btn btn-sm btn-primary"><i class="bi bi-send"></i> Revisar e publicar de novo</a>
        <?php endif ?>
    </div>
</div>

<?php if (! empty($listing['last_error'])): ?>
    <div class="alert alert-danger small"><i class="bi bi-exclamation-octagon me-1"></i> Último erro: <?= esc($listing['last_error']) ?></div>
<?php endif ?>

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Anúncio</div>
            <div class="card-body">
                <dl class="dl-compact row small mb-0">
                    <dt class="col-5">ID Mercado Livre</dt><dd class="col-7"><?= esc($listing['external_listing_id'] ?? '—') ?></dd>
                    <dt class="col-5">User Product</dt><dd class="col-7"><?= esc($listing['external_product_id'] ?? '—') ?></dd>
                    <?php if ($listing['family_name']): ?><dt class="col-5">Família</dt><dd class="col-7"><?= esc($listing['family_name']) ?></dd><?php endif ?>
                    <dt class="col-5">Categoria</dt><dd class="col-7"><a href="<?= site_url('marketplaces/mercadolivre/categories?category=' . rawurlencode($listing['category_id'])) ?>"><?= esc($listing['category_id']) ?></a></dd>
                    <dt class="col-5">Tipo de anúncio</dt><dd class="col-7"><?= esc($listing['listing_type']) ?></dd>
                    <dt class="col-5">Condição</dt><dd class="col-7"><?= esc($listing['item_condition']) ?></dd>
                    <dt class="col-5">Situação</dt><dd class="col-7"><?= listing_status_badge($listing['status'], $listing['sub_status']) ?></dd>
                    <dt class="col-5">Publicado em</dt><dd class="col-7"><?= format_datetime($listing['published_at']) ?></dd>
                    <dt class="col-5">Última sincronização</dt><dd class="col-7"><?= format_datetime($listing['last_sync_at']) ?></dd>
                </dl>
                <?php if ($attributes !== []): ?>
                    <hr>
                    <div class="small">
                        <?php foreach ($attributes as $id => $value): ?>
                            <span class="badge text-bg-light border me-1 mb-1"><?= esc($id) ?>: <?= esc($value) ?></span>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Preço, margem e estoque</div>
            <div class="card-body">
                <dl class="dl-compact row small mb-0">
                    <dt class="col-6">Produto</dt>
                    <dd class="col-6">
                        <?php if ($listing['product_id'] !== null): ?>
                            <a href="<?= site_url('products/' . $listing['product_id']) ?>"><?= esc($listing['product_name']) ?></a> <code><?= esc($listing['sku']) ?></code>
                        <?php else: ?>Excluído<?php endif ?>
                    </dd>
                    <dt class="col-6">Fornecedor selecionado</dt><dd class="col-6"><?= esc($listing['supplier_name'] ?? '—') ?></dd>
                    <dt class="col-6">Preço custo (+ frete)</dt><dd class="col-6"><?= money($listing['cost_price']) ?><?= $listing['shipping_cost'] !== null ? ' + ' . money($listing['shipping_cost']) : '' ?></dd>
                    <dt class="col-6">Preço no Mercado Livre</dt><dd class="col-6 fw-semibold"><?= money($listing['price']) ?> <?= esc($listing['currency_id']) ?></dd>
                    <dt class="col-6">Comissão / tarifa fixa</dt>
                    <dd class="col-6"><?= $listing['sale_fee_percentage'] !== null ? percent_format($listing['sale_fee_percentage'], 2) . ' + ' . money($listing['sale_fixed_fee']) : '—' ?></dd>
                    <dt class="col-6">Margem atual</dt><dd class="col-6"><?= margin_badge($listing['margin'], $listing['below_minimum'] ? 'below_minimum' : 'ok') ?></dd>
                    <dt class="col-6">Estoque no anúncio</dt><dd class="col-6"><?= (int) $listing['available_quantity'] ?></dd>
                    <dt class="col-6">Estoque seguro do fornecedor</dt><dd class="col-6"><?= $listing['available_stock'] !== null ? (int) $listing['available_stock'] : '—' ?></dd>
                </dl>
                <div class="small text-body-secondary mt-2">A margem usa o custo atual do fornecedor principal e a tarifa consultada na última publicação ou atualização de preço.</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold">Histórico</div>
    <?php if ($history === []): ?>
        <div class="card-body small text-body-secondary">Sem operações registradas.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Quando</th><th>Operação</th><th>Resultado</th><th>Detalhe</th><th>Usuário</th></tr></thead>
                <tbody>
                    <?php foreach ($history as $event): ?>
                        <tr>
                            <td class="small text-nowrap"><?= format_datetime($event['created_at']) ?></td>
                            <td class="small"><?= esc(MarketplaceListingEventModel::ACTION_LABELS[$event['action']] ?? $event['action']) ?></td>
                            <td><?= sync_status_badge($event['status']) ?></td>
                            <td class="small"><?= esc($event['message']) ?></td>
                            <td class="small"><?= esc($event['user_name'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

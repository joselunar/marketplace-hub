<?php

use App\Models\MarketplaceListingModel;

?>
<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?= $this->include('mercadolivre/_tabs') ?>

<div class="alert alert-light border small d-flex align-items-center gap-2">
    <i class="bi bi-shield-check text-success"></i>
    <span>
        Publicação automática <strong><?= $automatic ? 'ligada nas configurações, mas ainda não executa nada' : 'desativada' ?></strong>:
        todo anúncio passa pela prévia e só é enviado depois da sua confirmação. Para publicar, abra um produto e use <em>Publicar no Mercado Livre</em>.
    </span>
</div>

<div class="card filters-card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-4">
                <label class="form-label" for="q">Produto, SKU ou ID do anúncio</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>" placeholder="Ex.: MLB1234567890">
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Todos</option>
                    <?php foreach (MarketplaceListingModel::STATUS_LABELS as $value => $label): ?>
                        <option value="<?= esc($value, 'attr') ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <?php if (count($accounts) > 1): ?>
                <div class="col-sm-3 col-lg-2">
                    <label class="form-label" for="account">Conta</label>
                    <select class="form-select form-select-sm" id="account" name="account">
                        <option value="">Todas</option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?= (int) $account['id'] ?>" <?= ($filters['account'] ?? '') === (string) $account['id'] ? 'selected' : '' ?>><?= esc($account['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            <?php endif ?>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
                <a class="btn btn-sm btn-light" href="<?= site_url('marketplaces/mercadolivre/listings') ?>">Limpar</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <?php if ($listings === []): ?>
        <?= view('partials/empty_state', ['icon' => 'megaphone', 'message' => $filters === [] ? 'Nenhum anúncio publicado ainda. Abra um produto e clique em "Publicar no Mercado Livre".' : 'Nenhum anúncio encontrado com esses filtros.']) ?>
        <?php if ($filters === []): ?>
            <div class="text-center pb-4"><a href="<?= site_url('products') ?>" class="btn btn-sm btn-primary">Ir para produtos</a></div>
        <?php endif ?>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Produto</th>
                        <th>Fornecedor selecionado</th>
                        <th class="text-end">Preço custo</th>
                        <th class="text-end">Preço ML</th>
                        <th class="text-end">Margem</th>
                        <th class="text-center">Estoque</th>
                        <th>Status</th>
                        <th>ID Mercado Livre</th>
                        <th>Última sincronização</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($listings as $listing): ?>
                        <?php
                        $published = ! empty($listing['external_listing_id']);
                        $stockDiff = $listing['available_stock'] !== null && (int) $listing['available_stock'] !== (int) $listing['available_quantity'];
                        ?>
                        <tr>
                            <td>
                                <?php if ($listing['product_id'] !== null): ?>
                                    <a href="<?= site_url('products/' . $listing['product_id']) ?>" class="fw-medium text-decoration-none"><?= esc($listing['product_name']) ?></a>
                                    <div class="small text-body-secondary"><code><?= esc($listing['sku']) ?></code> · <?= esc(MarketplaceListingModel::MODEL_LABELS[$listing['selling_model']] ?? $listing['selling_model']) ?></div>
                                <?php else: ?>
                                    <span class="text-body-secondary">Produto excluído</span>
                                <?php endif ?>
                            </td>
                            <td><?= esc($listing['supplier_name'] ?? '—') ?></td>
                            <td class="text-end text-nowrap"><?= money($listing['cost_price']) ?></td>
                            <td class="text-end text-nowrap fw-semibold">
                                <?php if (MarketplaceListingModel::hasPriceAutomation($listing)): ?>
                                    <i class="bi bi-robot text-primary" title="Automação de preço: <?= esc(money($listing['price_automation_min']) . ' a ' . money($listing['price_automation_max']), 'attr') ?>"></i>
                                <?php endif ?>
                                <?= money($listing['price']) ?>
                            </td>
                            <td class="text-end"><?= margin_badge($listing['margin'], $listing['below_minimum'] ? 'below_minimum' : 'ok') ?></td>
                            <td class="text-center">
                                <?= (int) $listing['available_quantity'] ?>
                                <?php if ($stockDiff): ?>
                                    <div class="small text-warning-emphasis" title="Estoque seguro do fornecedor principal">fornecedor: <?= (int) $listing['available_stock'] ?></div>
                                <?php endif ?>
                            </td>
                            <td><?= listing_status_badge($listing['status'], $listing['sub_status']) ?></td>
                            <td class="text-nowrap">
                                <?php if ($published && $listing['permalink']): ?>
                                    <a href="<?= esc($listing['permalink'], 'attr') ?>" target="_blank" rel="noopener noreferrer"><?= esc($listing['external_listing_id']) ?> <i class="bi bi-box-arrow-up-right small"></i></a>
                                <?php else: ?>
                                    <?= esc($listing['external_listing_id'] ?? '—') ?>
                                <?php endif ?>
                            </td>
                            <td class="small text-nowrap"><?= relative_time($listing['last_sync_at']) ?></td>
                            <td class="text-end text-nowrap">
                                <a href="<?= site_url('marketplaces/mercadolivre/listings/' . $listing['id']) ?>" class="btn btn-sm btn-outline-secondary" title="Visualizar"><i class="bi bi-eye"></i></a>
                                <?php if ($published): ?>
                                    <?php foreach ([
                                        ['sync', 'arrow-repeat', 'Sincronizar', null],
                                        ['price', 'currency-dollar', 'Atualizar preço', 'Recalcular o preço com a tarifa oficial e enviar ao Mercado Livre?'],
                                        ['stock', 'box-seam', 'Atualizar estoque', 'Enviar o estoque seguro do fornecedor principal ao Mercado Livre?'],
                                    ] as [$action, $icon, $label, $confirm]): ?>
                                        <form action="<?= site_url('marketplaces/mercadolivre/listings/' . $listing['id'] . '/' . $action) ?>" method="post" class="d-inline" <?= $confirm ? 'data-confirm="' . esc($confirm, 'attr') . '"' : '' ?>>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="return_to" value="list">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="<?= esc($label, 'attr') ?>"><i class="bi bi-<?= $icon ?>"></i></button>
                                        </form>
                                    <?php endforeach ?>
                                    <?php if ($listing['status'] === MarketplaceListingModel::STATUS_ACTIVE): ?>
                                        <form action="<?= site_url('marketplaces/mercadolivre/listings/' . $listing['id'] . '/pause') ?>" method="post" class="d-inline" data-confirm="Pausar o anúncio <?= esc($listing['external_listing_id'], 'attr') ?>?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="return_to" value="list">
                                            <button type="submit" class="btn btn-sm btn-outline-warning" title="Pausar"><i class="bi bi-pause-fill"></i></button>
                                        </form>
                                    <?php elseif ($listing['status'] === MarketplaceListingModel::STATUS_PAUSED): ?>
                                        <form action="<?= site_url('marketplaces/mercadolivre/listings/' . $listing['id'] . '/activate') ?>" method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="return_to" value="list">
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Reativar"><i class="bi bi-play-fill"></i></button>
                                        </form>
                                    <?php endif ?>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?= $this->include('partials/pagination') ?>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

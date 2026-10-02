<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="card filters-card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="q">Buscar</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>" placeholder="SKU ou nome">
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="supplier_id">Fornecedor</label>
                <select class="form-select form-select-sm" id="supplier_id" name="supplier_id">
                    <option value="">Todos</option>
                    <?php foreach ($suppliers as $id => $name): ?>
                        <option value="<?= $id ?>" <?= ($filters['supplier_id'] ?? '') === (string) $id ? 'selected' : '' ?>><?= esc($name) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-sm-4 col-lg-2">
                <label class="form-label" for="link">Vínculo</label>
                <select class="form-select form-select-sm" id="link" name="link">
                    <option value="">Todos</option>
                    <option value="linked" <?= ($filters['link'] ?? '') === 'linked' ? 'selected' : '' ?>>Vinculadas</option>
                    <option value="unlinked" <?= ($filters['link'] ?? '') === 'unlinked' ? 'selected' : '' ?>>Sem vínculo</option>
                </select>
            </div>
            <div class="col-sm-4 col-lg-2">
                <label class="form-label" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Todos</option>
                    <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Ativas</option>
                    <option value="inactive" <?= ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inativas</option>
                    <option value="out_of_stock" <?= ($filters['status'] ?? '') === 'out_of_stock' ? 'selected' : '' ?>>Sem estoque</option>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
                <a class="btn btn-sm btn-light" href="<?= site_url('offers') ?>">Limpar</a>
            </div>
            <div class="col text-end">
                <a href="<?= site_url('offers/new') ?>" class="btn btn-sm btn-success"><i class="bi bi-plus-lg me-1"></i> Nova oferta</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>Fornecedor</th>
                <th>SKU fornecedor</th>
                <th>Nome no fornecedor</th>
                <th>Produto interno</th>
                <th class="text-end">Custo</th>
                <th class="text-center">Estoque</th>
                <th class="text-end">Frete</th>
                <th>Atualização</th>
                <th>Status</th>
                <th class="text-end">Ações</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($offers as $offer): ?>
                <tr>
                    <td class="small"><a href="<?= site_url('suppliers/' . $offer['supplier_id']) ?>" class="text-decoration-none"><?= esc($offer['supplier_name']) ?></a></td>
                    <td class="small"><code><?= esc($offer['supplier_sku']) ?></code></td>
                    <td class="small"><?= esc($offer['supplier_product_name']) ?></td>
                    <td class="small">
                        <?php if ($offer['product_id']): ?>
                            <a href="<?= site_url('products/' . $offer['product_id']) ?>" class="text-decoration-none"><?= esc($offer['product_sku']) ?></a>
                        <?php else: ?>
                            <?= badge('Sem vínculo', 'warning') ?>
                        <?php endif ?>
                    </td>
                    <td class="text-end text-nowrap"><?= money($offer['cost_price']) ?></td>
                    <td class="text-center"><?= stock_badge($offer['stock_quantity']) ?></td>
                    <td class="text-end text-nowrap small"><?= money($offer['shipping_cost']) ?></td>
                    <td class="small"><?= format_datetime($offer['last_sync_at'] ?? $offer['updated_at']) ?></td>
                    <td><?= active_badge($offer['active']) ?></td>
                    <td class="text-end text-nowrap">
                        <a href="<?= site_url('offers/' . $offer['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary" title="Editar / vincular"><i class="bi bi-pencil"></i></a>
                        <?php if (is_admin()): ?>
                            <form action="<?= site_url('offers/' . $offer['id'] . '/delete') ?>" method="post" class="d-inline" data-confirm="Excluir a oferta <?= esc($offer['supplier_sku'], 'attr') ?>?">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                            </form>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php if ($offers === []): ?>
        <?= $this->include('partials/empty_state') ?>
    <?php endif ?>
    <?= $this->include('partials/pagination') ?>
</div>
<?= $this->endSection() ?>

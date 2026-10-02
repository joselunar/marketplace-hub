<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="card filters-card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="q">Nome</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>">
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label" for="sku">SKU</label>
                <input type="text" class="form-control form-control-sm" id="sku" name="sku" value="<?= esc($filters['sku'] ?? '', 'attr') ?>">
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label" for="ean">EAN</label>
                <input type="text" class="form-control form-control-sm" id="ean" name="ean" value="<?= esc($filters['ean'] ?? '', 'attr') ?>" inputmode="numeric">
            </div>
            <div class="col-sm-4 col-lg-2">
                <label class="form-label" for="brand">Marca</label>
                <input type="text" class="form-control form-control-sm" id="brand" name="brand" value="<?= esc($filters['brand'] ?? '', 'attr') ?>">
            </div>
            <div class="col-sm-4 col-lg-3">
                <label class="form-label" for="category_id">Categoria</label>
                <select class="form-select form-select-sm" id="category_id" name="category_id">
                    <option value="">Todas</option>
                    <?php foreach ($categories as $id => $label): ?>
                        <option value="<?= $id ?>" <?= ($filters['category_id'] ?? '') === (string) $id ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-sm-4 col-lg-2">
                <label class="form-label" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Todos</option>
                    <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Ativos</option>
                    <option value="inactive" <?= ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inativos</option>
                </select>
            </div>
            <div class="col-sm-4 col-lg-2">
                <label class="form-label" for="supplier">Fornecedor</label>
                <select class="form-select form-select-sm" id="supplier" name="supplier">
                    <option value="">Todos</option>
                    <option value="with" <?= ($filters['supplier'] ?? '') === 'with' ? 'selected' : '' ?>>Possui fornecedor</option>
                    <option value="without" <?= ($filters['supplier'] ?? '') === 'without' ? 'selected' : '' ?>>Sem fornecedor</option>
                </select>
            </div>
            <div class="col-sm-4 col-lg-2">
                <label class="form-label" for="selection">Seleção</label>
                <select class="form-select form-select-sm" id="selection" name="selection">
                    <option value="">Todas</option>
                    <option value="selected" <?= ($filters['selection'] ?? '') === 'selected' ? 'selected' : '' ?>>Com fornecedor elegível</option>
                    <option value="none" <?= ($filters['selection'] ?? '') === 'none' ? 'selected' : '' ?>>Sem fornecedor elegível</option>
                </select>
            </div>
            <div class="col-sm-4 col-lg-2">
                <label class="form-label" for="stock">Estoque</label>
                <select class="form-select form-select-sm" id="stock" name="stock">
                    <option value="">Todos</option>
                    <option value="in" <?= ($filters['stock'] ?? '') === 'in' ? 'selected' : '' ?>>Com estoque</option>
                    <option value="out" <?= ($filters['stock'] ?? '') === 'out' ? 'selected' : '' ?>>Sem estoque</option>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
                <a class="btn btn-sm btn-light" href="<?= site_url('products') ?>">Limpar</a>
            </div>
            <div class="col text-end">
                <a href="<?= site_url('products/new') ?>" class="btn btn-sm btn-success"><i class="bi bi-plus-lg me-1"></i> Novo produto</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>SKU</th>
                <th>Produto</th>
                <th>Marca</th>
                <th>Categoria</th>
                <th class="text-center">Ofertas</th>
                <th class="text-end">Menor custo</th>
                <th class="text-center">Estoque fornecedores</th>
                <th>Fornecedor selecionado</th>
                <th>Status</th>
                <th class="text-end">Ações</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $product): ?>
                <tr>
                    <td class="small text-nowrap"><code><?= esc($product['sku']) ?></code></td>
                    <td>
                        <a href="<?= site_url('products/' . $product['id']) ?>" class="fw-medium text-decoration-none"><?= esc($product['name']) ?></a>
                        <?php if ($product['ean']): ?>
                            <div class="small text-body-secondary">EAN <?= esc($product['ean']) ?></div>
                        <?php endif ?>
                    </td>
                    <td class="small"><?= esc($product['brand'] ?? '—') ?></td>
                    <td class="small"><?= esc($product['category_name'] ?? '—') ?></td>
                    <td class="text-center">
                        <?= (int) $product['offers_count'] > 0 ? (int) $product['offers_count'] : badge('Sem fornecedor', 'warning') ?>
                    </td>
                    <td class="text-end small text-nowrap"><?= money($product['min_cost']) ?></td>
                    <td class="text-center"><?= stock_badge($product['total_stock']) ?></td>
                    <td class="small">
                        <?php if ($product['selected_supplier_name'] !== null): ?>
                            <?= esc($product['selected_supplier_name']) ?> <span class="text-body-secondary">(<?= score_format($product['selected_score']) ?>)</span>
                        <?php elseif ((int) $product['offers_count'] > 0): ?>
                            <?= badge('Nenhum elegível', 'danger') ?>
                        <?php else: ?>
                            <span class="text-body-secondary">—</span>
                        <?php endif ?>
                    </td>
                    <td><?= active_badge($product['active']) ?></td>
                    <td class="text-end text-nowrap">
                        <a href="<?= site_url('products/' . $product['id']) ?>" class="btn btn-sm btn-outline-secondary" title="Detalhes"><i class="bi bi-eye"></i></a>
                        <a href="<?= site_url('products/' . $product['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary" title="Editar"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php if ($products === []): ?>
        <?= $this->include('partials/empty_state') ?>
    <?php endif ?>
    <?= $this->include('partials/pagination') ?>
</div>
<?= $this->endSection() ?>

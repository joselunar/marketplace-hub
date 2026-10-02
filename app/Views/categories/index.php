<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="card filters-card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-4">
                <label class="form-label" for="q">Buscar</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>" placeholder="Nome da categoria">
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
                <a class="btn btn-sm btn-light" href="<?= site_url('categories') ?>">Limpar</a>
            </div>
            <div class="col text-end">
                <a href="<?= site_url('categories/new') ?>" class="btn btn-sm btn-success"><i class="bi bi-plus-lg me-1"></i> Nova categoria</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>Nome</th>
                <th>Categoria pai</th>
                <th>Slug</th>
                <th class="text-center">Margem desejada / mínima</th>
                <th class="text-center">Produtos</th>
                <th>Status</th>
                <th class="text-end">Ações</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($categories as $category): ?>
                <tr>
                    <td class="fw-medium"><?= esc($category['name']) ?></td>
                    <td class="small"><?= esc($category['parent_name'] ?? '—') ?></td>
                    <td class="small text-body-secondary"><code><?= esc($category['slug']) ?></code></td>
                    <td class="text-center small">
                        <?php if ($category['desired_margin_percentage'] === null && $category['minimum_margin_percentage'] === null): ?>
                            <span class="text-body-secondary">Herdada</span>
                        <?php else: ?>
                            <?= $category['desired_margin_percentage'] !== null ? percent_format($category['desired_margin_percentage']) : '<span class="text-body-secondary">herdada</span>' ?>
                            /
                            <?= $category['minimum_margin_percentage'] !== null ? percent_format($category['minimum_margin_percentage']) : '<span class="text-body-secondary">herdada</span>' ?>
                        <?php endif ?>
                    </td>
                    <td class="text-center">
                        <a href="<?= site_url('products?category_id=' . $category['id']) ?>"><?= (int) $category['products_count'] ?></a>
                    </td>
                    <td><?= active_badge($category['active']) ?></td>
                    <td class="text-end text-nowrap">
                        <a href="<?= site_url('categories/' . $category['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary" title="Editar"><i class="bi bi-pencil"></i></a>
                        <?php if (is_admin()): ?>
                            <form action="<?= site_url('categories/' . $category['id'] . '/delete') ?>" method="post" class="d-inline" data-confirm="Excluir a categoria <?= esc($category['name'], 'attr') ?>?">
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
    <?php if ($categories === []): ?>
        <?= $this->include('partials/empty_state') ?>
    <?php endif ?>
    <?= $this->include('partials/pagination') ?>
</div>
<?= $this->endSection() ?>

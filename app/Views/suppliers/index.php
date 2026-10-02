<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="card filters-card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-4">
                <label class="form-label" for="q">Buscar</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>" placeholder="Nome, razão social ou documento">
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Todos</option>
                    <?php foreach ($statuses as $value => $label): ?>
                        <option value="<?= esc($value, 'attr') ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label" for="integration_type">Integração</label>
                <select class="form-select form-select-sm" id="integration_type" name="integration_type">
                    <option value="">Todas</option>
                    <?php foreach ($integrationTypes as $value => $label): ?>
                        <option value="<?= esc($value, 'attr') ?>" <?= ($filters['integration_type'] ?? '') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
                <a class="btn btn-sm btn-light" href="<?= site_url('suppliers') ?>">Limpar</a>
            </div>
            <div class="col text-end">
                <a href="<?= site_url('suppliers/new') ?>" class="btn btn-sm btn-success"><i class="bi bi-plus-lg me-1"></i> Novo fornecedor</a>
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
                <th>Integração</th>
                <th class="text-center">Prioridade</th>
                <th class="text-center">Ofertas</th>
                <th>Última sincronização</th>
                <th>Status</th>
                <th class="text-end">Ações</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($suppliers as $supplier): ?>
                <tr>
                    <td>
                        <a href="<?= site_url('suppliers/' . $supplier['id']) ?>" class="fw-medium text-decoration-none"><?= esc($supplier['name']) ?></a>
                        <?php if ($supplier['legal_name']): ?>
                            <div class="small text-body-secondary"><?= esc($supplier['legal_name']) ?></div>
                        <?php endif ?>
                    </td>
                    <td class="small"><?= esc(integration_type_label($supplier['integration_type'])) ?></td>
                    <td class="text-center"><?= (int) $supplier['priority'] ?></td>
                    <td class="text-center"><?= (int) $supplier['offers_count'] ?></td>
                    <td class="small"><?= format_datetime($supplier['last_offer_sync_at'], 'Nunca') ?></td>
                    <td><?= supplier_status_badge($supplier['status']) ?></td>
                    <td class="text-end text-nowrap">
                        <a href="<?= site_url('suppliers/' . $supplier['id']) ?>" class="btn btn-sm btn-outline-secondary" title="Detalhes"><i class="bi bi-eye"></i></a>
                        <a href="<?= site_url('suppliers/' . $supplier['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary" title="Editar"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php if ($suppliers === []): ?>
        <?= $this->include('partials/empty_state') ?>
    <?php endif ?>
    <?= $this->include('partials/pagination') ?>
</div>
<?= $this->endSection() ?>

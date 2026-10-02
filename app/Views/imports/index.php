<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="card filters-card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="supplier_id">Fornecedor</label>
                <select class="form-select form-select-sm" id="supplier_id" name="supplier_id">
                    <option value="">Todos</option>
                    <?php foreach ($suppliers as $id => $name): ?>
                        <option value="<?= $id ?>" <?= ($filters['supplier_id'] ?? '') === (string) $id ? 'selected' : '' ?>><?= esc($name) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-sm-4 col-lg-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Todos</option>
                    <?php foreach (['completed' => 'Concluída', 'completed_with_errors' => 'Concluída com erros', 'failed' => 'Falhou', 'processing' => 'Processando'] as $k => $v): ?>
                        <option value="<?= $k ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
                <a class="btn btn-sm btn-light" href="<?= site_url('imports') ?>">Limpar</a>
            </div>
            <div class="col text-end">
                <a href="<?= site_url('imports/new') ?>" class="btn btn-sm btn-success"><i class="bi bi-cloud-upload me-1"></i> Importar CSV</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>#</th>
                <th>Fornecedor</th>
                <th>Arquivo</th>
                <th>Status</th>
                <th class="text-center">Linhas</th>
                <th class="text-center">Criadas</th>
                <th class="text-center">Atualizadas</th>
                <th class="text-center">Vinculadas</th>
                <th class="text-center">Erros</th>
                <th>Usuário</th>
                <th>Data</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($imports as $import): ?>
                <tr>
                    <td><a href="<?= site_url('imports/' . $import['id']) ?>"><?= (int) $import['id'] ?></a></td>
                    <td class="small"><?= esc($import['supplier_name']) ?></td>
                    <td class="small text-truncate" style="max-width: 220px"><?= esc($import['original_filename'] ?? '') ?></td>
                    <td><?= sync_status_badge($import['status']) ?></td>
                    <td class="text-center"><?= (int) $import['total_rows'] ?></td>
                    <td class="text-center"><?= (int) $import['created_count'] ?></td>
                    <td class="text-center"><?= (int) $import['updated_count'] ?></td>
                    <td class="text-center"><?= (int) $import['linked_count'] ?></td>
                    <td class="text-center"><?= (int) $import['error_count'] > 0 ? badge((string) $import['error_count'], 'danger') : '0' ?></td>
                    <td class="small"><?= esc($import['user_name'] ?? '—') ?></td>
                    <td class="small text-nowrap"><?= format_datetime($import['created_at']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php if ($imports === []): ?>
        <?= $this->include('partials/empty_state') ?>
    <?php endif ?>
    <?= $this->include('partials/pagination') ?>
</div>
<?= $this->endSection() ?>

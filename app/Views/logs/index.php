<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'audit' ? 'active' : '' ?>" href="<?= site_url('logs') ?>">Auditoria</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'sync' ? 'active' : '' ?>" href="<?= site_url('logs?tab=sync') ?>">Sincronizações</a></li>
</ul>

<div class="card filters-card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?= esc($tab, 'attr') ?>">
            <?php if ($tab === 'audit'): ?>
                <div class="col-sm-4 col-lg-3">
                    <label class="form-label" for="entity_type">Entidade</label>
                    <select class="form-select form-select-sm" id="entity_type" name="entity_type">
                        <option value="">Todas</option>
                        <?php foreach (['user' => 'Usuário', 'supplier' => 'Fornecedor', 'product' => 'Produto', 'category' => 'Categoria', 'supplier_offer' => 'Oferta', 'settings' => 'Configurações'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= ($filters['entity_type'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="col-sm-4 col-lg-3">
                    <label class="form-label" for="action">Ação</label>
                    <select class="form-select form-select-sm" id="action" name="action">
                        <option value="">Todas</option>
                        <?php foreach (['create' => 'Criação', 'update' => 'Alteração', 'delete' => 'Exclusão', 'login' => 'Login', 'logout' => 'Logout', 'import' => 'Importação', 'link' => 'Vínculo', 'unlink' => 'Desvínculo'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= ($filters['action'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            <?php else: ?>
                <div class="col-sm-4 col-lg-3">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select form-select-sm" id="status" name="status">
                        <option value="">Todos</option>
                        <?php foreach (['success' => 'Sucesso', 'warning' => 'Com avisos', 'error' => 'Erro'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            <?php endif ?>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
                <a class="btn btn-sm btn-light" href="<?= site_url('logs' . ($tab === 'sync' ? '?tab=sync' : '')) ?>">Limpar</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <?php if ($tab === 'audit'): ?>
            <table class="table table-sm table-hover mb-0">
                <thead>
                <tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Entidade</th><th>Alterações</th><th>IP</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $old = $row['old_values'] !== null ? json_decode($row['old_values'], true) : null;
                    $new = $row['new_values'] !== null ? json_decode($row['new_values'], true) : null;
                    ?>
                    <tr>
                        <td class="small text-nowrap"><?= format_datetime($row['created_at']) ?></td>
                        <td class="small"><?= esc($row['user_name'] ?? 'Sistema') ?></td>
                        <td><?= badge($row['action'], 'secondary') ?></td>
                        <td class="small"><?= esc($row['entity_type']) ?><?= $row['entity_id'] ? ' #' . (int) $row['entity_id'] : '' ?></td>
                        <td class="small">
                            <?php if (is_array($new) && $new !== []): ?>
                                <details>
                                    <summary><?= count($new) ?> campo(s)</summary>
                                    <ul class="mb-0 ps-3">
                                        <?php foreach ($new as $field => $value): ?>
                                            <li>
                                                <code><?= esc((string) $field) ?></code>:
                                                <?php if (is_array($old) && array_key_exists($field, $old)): ?>
                                                    <span class="text-danger text-decoration-line-through"><?= esc(is_scalar($old[$field]) ? (string) $old[$field] : json_encode($old[$field], JSON_UNESCAPED_UNICODE)) ?></span> →
                                                <?php endif ?>
                                                <span class="text-success"><?= esc(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE)) ?></span>
                                            </li>
                                        <?php endforeach ?>
                                    </ul>
                                </details>
                            <?php else: ?>
                                <span class="text-body-secondary">—</span>
                            <?php endif ?>
                        </td>
                        <td class="small text-body-secondary"><?= esc($row['ip_address'] ?? '') ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php else: ?>
            <table class="table table-sm table-hover mb-0">
                <thead>
                <tr><th>Início</th><th>Integração</th><th>Ação</th><th>Status</th><th>Mensagem</th><th>Fim</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="small text-nowrap"><?= format_datetime($row['started_at']) ?></td>
                        <td class="small">
                            <?php if ($row['integration_type'] === 'supplier' && $row['integration_id']): ?>
                                <a href="<?= site_url('suppliers/' . (int) $row['integration_id']) ?>">Fornecedor #<?= (int) $row['integration_id'] ?></a>
                            <?php else: ?>
                                <?= esc($row['integration_type']) ?> <?= $row['integration_id'] ? '#' . (int) $row['integration_id'] : '' ?>
                            <?php endif ?>
                        </td>
                        <td class="small"><?= esc($row['action']) ?></td>
                        <td><?= sync_status_badge($row['status']) ?></td>
                        <td class="small"><?= esc($row['message'] ?? '') ?></td>
                        <td class="small text-nowrap"><?= format_datetime($row['finished_at']) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </div>
    <?php if ($rows === []): ?>
        <?= $this->include('partials/empty_state') ?>
    <?php endif ?>
    <?= $this->include('partials/pagination') ?>
</div>
<?= $this->endSection() ?>

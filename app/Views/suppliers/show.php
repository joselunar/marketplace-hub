<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 justify-content-end mb-3">
    <form action="<?= site_url('suppliers/' . $supplier['id'] . '/test-connection') ?>" method="post">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-plug me-1"></i> Testar integração</button>
    </form>
    <a href="<?= site_url('imports/new?supplier_id=' . $supplier['id']) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-cloud-upload me-1"></i> Importar CSV</a>
    <a href="<?= site_url('offers/new?supplier_id=' . $supplier['id']) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-plus-lg me-1"></i> Nova oferta</a>
    <a href="<?= site_url('suppliers/' . $supplier['id'] . '/edit') ?>" class="btn btn-primary btn-sm"><i class="bi bi-pencil me-1"></i> Editar</a>
    <?php if (is_admin()): ?>
        <form action="<?= site_url('suppliers/' . $supplier['id'] . '/delete') ?>" method="post" data-confirm="Excluir o fornecedor <?= esc($supplier['name'], 'attr') ?>? Só é possível excluir fornecedores sem ofertas.">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
        </form>
    <?php endif ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="small text-body-secondary">Status</div>
            <div class="mt-1"><?= supplier_status_badge($supplier['status']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="small text-body-secondary">Ofertas (produtos)</div>
            <div class="stat-value"><?= $offerStats['total'] ?></div>
            <div class="small text-body-secondary"><?= $offerStats['active'] ?> ativas · <?= $offerStats['unlinked'] ?> sem vínculo · <?= $offerStats['out_of_stock'] ?> sem estoque</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="small text-body-secondary">Tipo de integração</div>
            <div class="fw-semibold mt-1"><?= esc(integration_type_label($supplier['integration_type'])) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="small text-body-secondary">Última sincronização</div>
            <div class="fw-semibold mt-1"><?= format_datetime($lastSync['finished_at'] ?? $lastOfferSync, 'Nunca') ?></div>
            <?php if ($lastSync): ?>
                <div class="mt-1"><?= sync_status_badge($lastSync['status']) ?></div>
            <?php endif ?>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-5">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Dados gerais</div>
            <div class="card-body">
                <dl class="dl-compact mb-0">
                    <dt>Razão social</dt><dd><?= esc($supplier['legal_name'] ?: '—') ?></dd>
                    <dt>CNPJ/CPF</dt><dd><?= esc($supplier['document'] ?: '—') ?></dd>
                    <dt>E-mail</dt><dd><?= esc($supplier['email'] ?: '—') ?></dd>
                    <dt>Telefone</dt><dd><?= esc($supplier['phone'] ?: '—') ?></dd>
                    <dt>Site</dt>
                    <dd>
                        <?php if ($supplier['website']): ?>
                            <a href="<?= esc($supplier['website'], 'attr') ?>" target="_blank" rel="noopener noreferrer"><?= esc($supplier['website']) ?></a>
                        <?php else: ?>—<?php endif ?>
                    </dd>
                    <dt>Prioridade</dt><dd><?= (int) $supplier['priority'] ?> / 10</dd>
                    <dt>Prazo padrão de postagem</dt><dd><?= $supplier['default_shipping_days'] !== null ? (int) $supplier['default_shipping_days'] . ' dia(s)' : '—' ?></dd>
                    <dt>Estoque de segurança</dt><dd><?= $supplier['safety_stock'] !== null ? (int) $supplier['safety_stock'] . ' un.' : 'Global' ?></dd>
                    <dt>Observações</dt><dd class="text-pre-wrap"><?= nl2br(esc($supplier['notes'] ?: '—')) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-7">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Erros recentes</span>
                <a href="<?= site_url('logs?tab=sync') ?>" class="small">Ver logs</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($recentErrors as $log): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <span><?= sync_status_badge($log['status']) ?> <span class="small"><?= esc($log['action']) ?></span></span>
                            <span class="small text-body-secondary"><?= format_datetime($log['started_at']) ?></span>
                        </div>
                        <div class="small text-body-secondary mt-1"><?= esc($log['message'] ?? '') ?></div>
                    </li>
                <?php endforeach ?>
                <?php if ($recentErrors === []): ?>
                    <li class="list-group-item small text-body-secondary"><i class="bi bi-check-circle text-success me-1"></i> Nenhum erro registrado.</li>
                <?php endif ?>
            </ul>
        </div>

        <div class="card">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Importações recentes</span>
                <a href="<?= site_url('offers?supplier_id=' . $supplier['id']) ?>" class="small">Ver ofertas</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>#</th><th>Arquivo</th><th>Status</th><th>Linhas</th><th>Data</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentImports as $import): ?>
                        <tr>
                            <td><a href="<?= site_url('imports/' . $import['id']) ?>"><?= (int) $import['id'] ?></a></td>
                            <td class="small text-truncate" style="max-width: 220px"><?= esc($import['original_filename'] ?? '') ?></td>
                            <td><?= sync_status_badge($import['status']) ?></td>
                            <td class="small"><?= (int) $import['total_rows'] ?></td>
                            <td class="small"><?= format_datetime($import['created_at']) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php if ($recentImports === []): ?>
                <div class="small text-body-secondary p-3">Nenhuma importação realizada.</div>
            <?php endif ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

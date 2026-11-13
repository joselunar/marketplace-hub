<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$counts = $counts ?? [];
$labels = $labels ?? [];
$gate   = $gate ?? null;
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h2 class="h5 mb-1">Fiscal NF-e</h2>
        <div class="small text-body-secondary">
            Ambiente efetivo:
            <span class="badge text-bg-<?= ($gate && $gate->effectiveEnvironment() === 'producao') ? 'danger' : 'secondary' ?>">
                <?= esc($gate ? $gate->effectiveEnvironment() : 'homologacao') ?>
            </span>
            <?php if ($gate && ! $gate->realEmissionAuthorized()): ?>
                · emissão real bloqueada
            <?php endif ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= site_url('fiscal/company') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-building me-1"></i>Empresa e certificado</a>
        <form method="post" action="<?= site_url('fiscal/status-servico') ?>"><?= csrf_field() ?>
            <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-hdd-network me-1"></i>Status SEFAZ</button>
        </form>
    </div>
</div>

<div class="row g-2 mb-3">
    <?php
    $cards = [
        'autorizada'  => 'Autorizadas',
        'rejeitada'   => 'Rejeitadas',
        'aguardando'  => 'Aguardando',
        'cancelada'   => 'Canceladas',
        'rascunho'    => 'Rascunhos',
        'erro'        => 'Erros',
    ];
    foreach ($cards as $status => $label):
        $total = (int) ($counts[$status] ?? 0);
    ?>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="<?= site_url('fiscal?status=' . urlencode($status)) ?>" class="card text-decoration-none h-100">
                <div class="card-body py-3">
                    <div class="small text-body-secondary"><?= esc($label) ?></div>
                    <div class="fs-4 fw-semibold"><?= $total ?></div>
                </div>
            </a>
        </div>
    <?php endforeach ?>
</div>

<form method="get" class="card mb-3">
    <div class="card-body row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Situação</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">Todas</option>
                <?php foreach ($labels as $k => $v): ?>
                    <option value="<?= esc($k, 'attr') ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= esc($v) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Pedido / chave / número</label>
            <input type="text" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>" class="form-control form-control-sm" placeholder="MLB…, chave ou nº">
        </div>
        <div class="col-md-2">
            <label class="form-label">De</label>
            <input type="date" name="date_from" value="<?= esc($filters['date_from'] ?? '', 'attr') ?>" class="form-control form-control-sm">
        </div>
        <div class="col-md-2">
            <label class="form-label">Até</label>
            <input type="date" name="date_to" value="<?= esc($filters['date_to'] ?? '', 'attr') ?>" class="form-control form-control-sm">
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button class="btn btn-sm btn-primary">Filtrar</button>
            <a class="btn btn-sm btn-light" href="<?= site_url('fiscal') ?>">Limpar</a>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Série/Nº</th>
                    <th>Pedido</th>
                    <th>Ambiente</th>
                    <th>Situação</th>
                    <th>SEFAZ</th>
                    <th>ML</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="text-body-secondary p-4">Nenhum documento fiscal ainda. Abra um pedido pago e prepare a NF-e.</td></tr>
                <?php endif ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= (int) $row['id'] ?></td>
                        <td><?= (int) $row['serie'] ?> / <?= (int) $row['number'] ?></td>
                        <td>
                            <?php if (! empty($row['order_id'])): ?>
                                <a href="<?= site_url('orders/' . $row['order_id']) ?>"><?= esc($row['external_order_id'] ?? ('#' . $row['order_id'])) ?></a>
                            <?php else: ?>
                                —
                            <?php endif ?>
                        </td>
                        <td><span class="badge text-bg-light border"><?= esc($row['environment']) ?></span></td>
                        <td><?= esc($labels[$row['status']] ?? $row['status']) ?></td>
                        <td class="small"><?= esc(($row['sefaz_status_code'] ?? '') . ' ' . ($row['sefaz_message'] ?? '')) ?></td>
                        <td class="small"><?= esc($row['ml_send_status'] ?? '—') ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-light" href="<?= site_url('fiscal/' . $row['id']) ?>">Detalhe</a></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager): ?>
        <div class="card-footer bg-white"><?= $pager->links() ?></div>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

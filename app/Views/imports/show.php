<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="row g-3 mb-3">
    <?php
    $stats = [
        'Linhas lidas'        => $import['total_rows'],
        'Ofertas criadas'     => $import['created_count'],
        'Ofertas atualizadas' => $import['updated_count'],
        'Vinculadas ao catálogo' => $import['linked_count'],
        'Erros'               => $import['error_count'],
    ];
    ?>
    <?php foreach ($stats as $label => $number): ?>
        <div class="col-6 col-md-4 col-xl">
            <div class="card stat-card h-100"><div class="card-body">
                <div class="stat-value"><?= (int) $number ?></div>
                <div class="small text-body-secondary"><?= esc($label) ?></div>
            </div></div>
        </div>
    <?php endforeach ?>
</div>

<div class="card mb-3">
    <div class="card-body">
        <dl class="dl-compact row mb-0">
            <dt class="col-sm-3">Fornecedor</dt>
            <dd class="col-sm-9"><a href="<?= site_url('suppliers/' . $import['supplier_id']) ?>"><?= esc($import['supplier_name']) ?></a></dd>
            <dt class="col-sm-3">Arquivo</dt><dd class="col-sm-9"><?= esc($import['original_filename'] ?? '—') ?></dd>
            <dt class="col-sm-3">Status</dt><dd class="col-sm-9"><?= sync_status_badge($import['status']) ?></dd>
            <dt class="col-sm-3">Enviado por</dt><dd class="col-sm-9"><?= esc($import['user_name'] ?? '—') ?></dd>
            <dt class="col-sm-3">Início / fim</dt><dd class="col-sm-9"><?= format_datetime($import['started_at']) ?> → <?= format_datetime($import['finished_at']) ?></dd>
        </dl>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between">
        <span>Erros encontrados</span>
        <?php if ((int) $import['error_count'] > count($errors)): ?>
            <span class="small text-body-secondary fw-normal">Exibindo os primeiros <?= count($errors) ?> de <?= (int) $import['error_count'] ?>.</span>
        <?php endif ?>
    </div>
    <?php if ($errors === []): ?>
        <div class="p-3 small text-body-secondary"><i class="bi bi-check-circle text-success me-1"></i> Nenhum erro.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th style="width: 90px">Linha</th><th>Mensagem</th></tr></thead>
                <tbody>
                <?php foreach ($errors as $error): ?>
                    <tr>
                        <td><?= (int) ($error['line'] ?? 0) ?: '—' ?></td>
                        <td class="small"><?= esc((string) ($error['message'] ?? '')) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>

<div class="mt-3 d-flex gap-2">
    <a href="<?= site_url('offers?supplier_id=' . $import['supplier_id']) ?>" class="btn btn-outline-secondary btn-sm">Ver ofertas do fornecedor</a>
    <a href="<?= site_url('offers?supplier_id=' . $import['supplier_id'] . '&link=unlinked') ?>" class="btn btn-outline-secondary btn-sm">Ofertas sem vínculo</a>
</div>
<?= $this->endSection() ?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="alert <?= $ready ? 'alert-success' : 'alert-warning' ?>">
    <?php if ($ready): ?>
        Checklist Dropify completo com evidências. A proteção de publicação ainda exige classificação <strong>Rentável</strong>.
    <?php else: ?>
        Itens pendentes ou confirmados sem evidência. Produtos Dropify <strong>não</strong> podem ser publicados até haver confirmação documental.
    <?php endif ?>
</div>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold">Pendências operacionais</div>
    <div class="card-body p-0">
        <?php foreach ($items as $item): ?>
            <div class="border-bottom p-3">
                <form method="post" action="<?= site_url('profitability/checklist/' . $item['id']) ?>" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <div class="col-lg-6">
                        <div class="fw-semibold"><?= esc($item['label']) ?></div>
                        <div class="small text-body-secondary"><?= esc($item['item_code']) ?></div>
                    </div>
                    <div class="col-sm-4 col-lg-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <?php foreach ($statuses as $k => $v): ?>
                                <option value="<?= esc($k, 'attr') ?>" <?= $item['status'] === $k ? 'selected' : '' ?>><?= esc($v) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="col-sm-8 col-lg-3">
                        <label class="form-label">Evidência / anotação</label>
                        <input type="text" name="evidence_note" class="form-control form-control-sm" value="<?= esc((string) ($item['evidence_note'] ?? ''), 'attr') ?>" placeholder="Link, e-mail, data da resposta…">
                    </div>
                    <div class="col-lg-1">
                        <button type="submit" class="btn btn-sm btn-primary w-100">Salvar</button>
                    </div>
                </form>
            </div>
        <?php endforeach ?>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold">Minuta para enviar à Dropify (manual)</div>
    <div class="card-body">
        <p class="small text-body-secondary">O sistema não envia mensagens automaticamente. Copie e envie você mesmo.</p>
        <textarea class="form-control" rows="10" readonly><?= esc($message) ?></textarea>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="row g-3">
    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-body">
                <form action="<?= site_url('imports') ?>" method="post" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="supplier_id" class="form-label">Fornecedor *</label>
                        <select class="form-select<?= invalid_class('supplier_id') ?>" id="supplier_id" name="supplier_id" required>
                            <option value="">Selecione…</option>
                            <?php $selected = (string) (old('supplier_id') ?? $supplierId); ?>
                            <?php foreach ($suppliers as $id => $name): ?>
                                <option value="<?= $id ?>" <?= $selected === (string) $id ? 'selected' : '' ?>><?= esc($name) ?></option>
                            <?php endforeach ?>
                        </select>
                        <div class="invalid-feedback"><?= esc(field_error('supplier_id') ?? '') ?></div>
                    </div>
                    <div class="mb-3">
                        <label for="file" class="form-label">Arquivo CSV *</label>
                        <input type="file" class="form-control<?= invalid_class('file') ?>" id="file" name="file" accept=".csv,.txt,text/csv" required>
                        <div class="form-text">Até <?= number_format($maxFileKb / 1024, 0) ?> MB e <?= number_format($maxRows, 0, ',', '.') ?> linhas. Separador ";" "," ou TAB. UTF-8 ou padrão do Excel.</div>
                        <div class="invalid-feedback"><?= esc(field_error('file') ?? '') ?></div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-cloud-upload me-1"></i> Importar</button>
                        <a href="<?= site_url('imports/template') ?>" class="btn btn-outline-secondary"><i class="bi bi-download me-1"></i> Baixar modelo</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Como funciona</div>
            <div class="card-body small">
                <ul>
                    <li>Cada linha vira (ou atualiza) uma oferta identificada pelo <strong>SKU do fornecedor</strong>.</li>
                    <li>Ofertas novas são vinculadas automaticamente ao produto com o mesmo <strong>SKU interno</strong> (coluna <code>product_sku</code>) ou, na falta dele, com o mesmo <strong>EAN</strong>.</li>
                    <li>Sem correspondência, a oferta fica <em>sem vínculo</em> e pode ser associada depois em Ofertas.</li>
                    <li>Linhas inválidas são ignoradas e listadas no relatório; as demais são gravadas.</li>
                    <li>Ofertas que não aparecem no arquivo não são alteradas.</li>
                </ul>
                <table class="table table-sm mb-0">
                    <thead><tr><th>Coluna</th><th>Também aceita</th></tr></thead>
                    <tbody>
                    <?php foreach ($aliases as $column => $names): ?>
                        <tr>
                            <td><code><?= esc($column) ?></code><?= in_array($column, $required, true) ? ' *' : '' ?></td>
                            <td class="text-body-secondary"><?= esc(implode(', ', array_slice($names, 1))) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

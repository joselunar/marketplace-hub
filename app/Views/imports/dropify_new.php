<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Importar catálogo Dropify (CSV)</div>
            <div class="card-body">
                <form method="post" action="<?= site_url('imports/dropify') ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="supplier_id">Fornecedor</label>
                        <select class="form-select<?= isset($errors['supplier_id']) ? ' is-invalid' : '' ?>" id="supplier_id" name="supplier_id" required>
                            <option value="">Selecione…</option>
                            <?php foreach ($suppliers as $id => $name): ?>
                                <option value="<?= $id ?>" <?= (string) $supplierId === (string) $id ? 'selected' : '' ?>><?= esc($name) ?></option>
                            <?php endforeach ?>
                        </select>
                        <div class="form-text">Se o fornecedor ainda não tiver <code>provider_code=dropify</code>, a importação marca automaticamente.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="file">Arquivo CSV</label>
                        <input type="file" class="form-control<?= isset($errors['file']) ? ' is-invalid' : '' ?>" id="file" name="file" accept=".csv,text/csv" required>
                        <?php if (isset($errors['file'])): ?><div class="invalid-feedback"><?= esc($errors['file']) ?></div><?php endif ?>
                        <div class="form-text">Máx. <?= (int) $maxFileKb ?> KB · até <?= (int) $maxRows ?> linhas · UTF-8 com BOM e delimitador ; ou ,</div>
                    </div>
                    <button type="submit" class="btn btn-success"><i class="bi bi-cloud-upload me-1"></i>Importar</button>
                    <a href="<?= site_url('imports') ?>" class="btn btn-light">Voltar</a>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Colunas reconhecidas</div>
            <div class="card-body small">
                <p>Obrigatórias: <?= esc(implode(', ', $required)) ?></p>
                <p class="mb-0">Também lê: categoria, marca, descrições, peso/dimensões, venda sugerida, EAN, NCM, CEST, origem fiscal, UF/CEP, imagens 1–6, prazo de postagem.</p>
                <hr>
                <p class="mb-0 text-body-secondary">A API Dropify permanece desligada (<code>dropify.api_enabled=false</code>). Esta tela não inventa endpoints nem usa credenciais.</p>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

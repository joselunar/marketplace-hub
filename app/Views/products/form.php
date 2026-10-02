<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$action = $product === null ? site_url('products') : site_url('products/' . $product['id']);
$value  = static fn (string $field, mixed $default = '') => old($field) ?? ($product[$field] ?? $default);
$active = old('active') ?? ($product['active'] ?? 1);
?>
<form action="<?= $action ?>" method="post" novalidate>
    <?= csrf_field() ?>

    <div class="row g-3">
        <div class="col-12 col-xl-8">
            <div class="card mb-3">
                <div class="card-header bg-white fw-semibold">Identificação</div>
                <div class="card-body row g-3">
                    <div class="col-md-4">
                        <label for="sku" class="form-label">SKU interno *</label>
                        <input type="text" class="form-control text-uppercase<?= invalid_class('sku') ?>" id="sku" name="sku" value="<?= esc($value('sku'), 'attr') ?>" required maxlength="60">
                        <div class="invalid-feedback"><?= esc(field_error('sku') ?? '') ?></div>
                    </div>
                    <div class="col-md-4">
                        <label for="ean" class="form-label">EAN/GTIN</label>
                        <input type="text" class="form-control<?= invalid_class('ean') ?>" id="ean" name="ean" value="<?= esc($value('ean'), 'attr') ?>" maxlength="14" inputmode="numeric">
                        <div class="invalid-feedback"><?= esc(field_error('ean') ?? '') ?></div>
                    </div>
                    <div class="col-md-4">
                        <label for="brand" class="form-label">Marca</label>
                        <input type="text" class="form-control<?= invalid_class('brand') ?>" id="brand" name="brand" value="<?= esc($value('brand'), 'attr') ?>" maxlength="100">
                        <div class="invalid-feedback"><?= esc(field_error('brand') ?? '') ?></div>
                    </div>
                    <div class="col-12">
                        <label for="name" class="form-label">Nome *</label>
                        <input type="text" class="form-control<?= invalid_class('name') . invalid_class('slug') ?>" id="name" name="name" value="<?= esc($value('name'), 'attr') ?>" required maxlength="200">
                        <div class="invalid-feedback"><?= esc(field_error('name') ?? field_error('slug') ?? '') ?></div>
                    </div>
                    <div class="col-12">
                        <label for="description" class="form-label">Descrição</label>
                        <textarea class="form-control<?= invalid_class('description') ?>" id="description" name="description" rows="6" maxlength="20000"><?= esc($value('description')) ?></textarea>
                        <div class="invalid-feedback"><?= esc(field_error('description') ?? '') ?></div>
                    </div>
                </div>
            </div>

            <div class="card" data-image-list data-max="<?= (int) $maxImages ?>">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span>Imagens (URLs públicas)</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-image-add><i class="bi bi-plus-lg"></i> Adicionar</button>
                </div>
                <div class="card-body">
                    <?php if (field_error('images')): ?>
                        <div class="alert alert-danger py-2 small"><?= esc(field_error('images')) ?></div>
                    <?php endif ?>
                    <p class="small text-body-secondary">A primeira imagem é a principal. Marketplaces exigem URLs acessíveis publicamente (https).</p>
                    <div data-image-items>
                        <?php foreach ($images as $index => $url): ?>
                            <div class="input-group mb-2" data-image-item>
                                <input type="url" class="form-control<?= invalid_class('images.' . $index) ?>" name="images[]" value="<?= esc((string) $url, 'attr') ?>" maxlength="500" placeholder="https://">
                                <button type="button" class="btn btn-outline-danger" data-image-remove title="Remover"><i class="bi bi-x-lg"></i></button>
                                <div class="invalid-feedback"><?= esc(field_error('images.' . $index) ?? '') ?></div>
                            </div>
                        <?php endforeach ?>
                    </div>
                    <template>
                        <div class="input-group mb-2" data-image-item>
                            <input type="url" class="form-control" name="images[]" maxlength="500" placeholder="https://">
                            <button type="button" class="btn btn-outline-danger" data-image-remove title="Remover"><i class="bi bi-x-lg"></i></button>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card mb-3">
                <div class="card-header bg-white fw-semibold">Organização</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="category_id" class="form-label">Categoria</label>
                        <select class="form-select<?= invalid_class('category_id') ?>" id="category_id" name="category_id">
                            <option value="">— Sem categoria —</option>
                            <?php foreach ($categories as $id => $label): ?>
                                <option value="<?= $id ?>" <?= (string) $value('category_id') === (string) $id ? 'selected' : '' ?>><?= esc($label) ?></option>
                            <?php endforeach ?>
                        </select>
                        <div class="invalid-feedback"><?= esc(field_error('category_id') ?? '') ?></div>
                    </div>
                    <div class="form-check form-switch">
                        <input type="hidden" name="active" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" <?= (int) $active === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="active">Produto ativo</label>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-white fw-semibold">Peso e dimensões (embalado)</div>
                <div class="card-body row g-3">
                    <?php foreach (['weight' => ['Peso (kg)', 3], 'height' => ['Altura (cm)', 2], 'width' => ['Largura (cm)', 2], 'length' => ['Comprimento (cm)', 2]] as $field => [$label, $decimals]): ?>
                        <?php $raw = $value($field); ?>
                        <div class="col-6">
                            <label for="<?= $field ?>" class="form-label"><?= $label ?></label>
                            <input type="text" inputmode="decimal" class="form-control<?= invalid_class($field) ?>" id="<?= $field ?>" name="<?= $field ?>"
                                   value="<?= esc(old($field) !== null ? (string) old($field) : decimal_br($raw, $decimals), 'attr') ?>">
                            <div class="invalid-feedback"><?= esc(field_error($field) ?? '') ?></div>
                        </div>
                    <?php endforeach ?>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Salvar</button>
        <a href="<?= site_url($product === null ? 'products' : 'products/' . $product['id']) ?>" class="btn btn-light">Cancelar</a>
    </div>
</form>
<?= $this->endSection() ?>

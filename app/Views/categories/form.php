<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$action = $category === null ? site_url('categories') : site_url('categories/' . $category['id']);
$value  = static fn (string $field, mixed $default = '') => old($field) ?? ($category[$field] ?? $default);
$active = old('active') ?? ($category['active'] ?? 1);
?>
<div class="card" style="max-width: 720px">
    <div class="card-body">
        <form action="<?= $action ?>" method="post" novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="name" class="form-label">Nome *</label>
                <input type="text" class="form-control<?= invalid_class('name') . invalid_class('slug') ?>" id="name" name="name" value="<?= esc($value('name'), 'attr') ?>" required maxlength="120">
                <div class="invalid-feedback"><?= esc(field_error('name') ?? field_error('slug') ?? '') ?></div>
            </div>

            <div class="mb-3">
                <label for="parent_id" class="form-label">Categoria pai</label>
                <select class="form-select<?= invalid_class('parent_id') ?>" id="parent_id" name="parent_id">
                    <option value="">— Nenhuma (categoria principal) —</option>
                    <?php foreach ($parents as $id => $label): ?>
                        <option value="<?= $id ?>" <?= (string) $value('parent_id') === (string) $id ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
                <div class="invalid-feedback"><?= esc(field_error('parent_id') ?? '') ?></div>
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Descrição</label>
                <textarea class="form-control<?= invalid_class('description') ?>" id="description" name="description" rows="3" maxlength="2000"><?= esc($value('description')) ?></textarea>
                <div class="invalid-feedback"><?= esc(field_error('description') ?? '') ?></div>
            </div>

            <div class="form-check form-switch mb-4">
                <input type="hidden" name="active" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" <?= (int) $active === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="active">Categoria ativa</label>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Salvar</button>
                <a href="<?= site_url('categories') ?>" class="btn btn-light">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?= $this->endSection() ?>

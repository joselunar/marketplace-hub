<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$action = $user === null ? site_url('users') : site_url('users/' . $user['id']);
$value  = static fn (string $field, mixed $default = '') => old($field) ?? ($user[$field] ?? $default);
$active = old('active') ?? ($user['active'] ?? 1);
?>
<div class="card" style="max-width: 720px">
    <div class="card-body">
        <form action="<?= $action ?>" method="post" autocomplete="off" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label for="name" class="form-label">Nome *</label>
                    <input type="text" class="form-control<?= invalid_class('name') ?>" id="name" name="name" value="<?= esc($value('name'), 'attr') ?>" required maxlength="120">
                    <div class="invalid-feedback"><?= esc(field_error('name') ?? '') ?></div>
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label">E-mail *</label>
                    <input type="email" class="form-control<?= invalid_class('email') ?>" id="email" name="email" value="<?= esc($value('email'), 'attr') ?>" required maxlength="190">
                    <div class="invalid-feedback"><?= esc(field_error('email') ?? '') ?></div>
                </div>
                <div class="col-md-6">
                    <label for="role" class="form-label">Papel *</label>
                    <select class="form-select<?= invalid_class('role') ?>" id="role" name="role" <?= $isSelf ? 'disabled' : '' ?>>
                        <?php foreach ($roles as $roleValue => $roleLabel): ?>
                            <option value="<?= esc($roleValue, 'attr') ?>" <?= $value('role', 'operator') === $roleValue ? 'selected' : '' ?>><?= esc($roleLabel) ?></option>
                        <?php endforeach ?>
                    </select>
                    <?php if ($isSelf): ?>
                        <input type="hidden" name="role" value="<?= esc($user['role'], 'attr') ?>">
                        <div class="form-text">Você não pode alterar o próprio papel.</div>
                    <?php endif ?>
                    <div class="invalid-feedback"><?= esc(field_error('role') ?? '') ?></div>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input type="hidden" name="active" value="<?= $isSelf ? '1' : '0' ?>">
                        <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" <?= (int) $active === 1 ? 'checked' : '' ?> <?= $isSelf ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="active">Usuário ativo</label>
                    </div>
                </div>

                <div class="col-12"><hr class="my-1"></div>

                <div class="col-md-6">
                    <label for="password" class="form-label">Senha <?= $user === null ? '*' : '' ?></label>
                    <input type="password" class="form-control<?= invalid_class('password') ?>" id="password" name="password" autocomplete="new-password" minlength="8" maxlength="72" <?= $user === null ? 'required' : '' ?>>
                    <div class="form-text"><?= $user === null ? 'Mínimo de 8 caracteres.' : 'Deixe em branco para manter a senha atual.' ?></div>
                    <div class="invalid-feedback"><?= esc(field_error('password') ?? '') ?></div>
                </div>
                <div class="col-md-6">
                    <label for="password_confirm" class="form-label">Confirmar senha</label>
                    <input type="password" class="form-control<?= invalid_class('password_confirm') ?>" id="password_confirm" name="password_confirm" autocomplete="new-password" maxlength="72">
                    <div class="invalid-feedback"><?= esc(field_error('password_confirm') ?? '') ?></div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Salvar</button>
                <a href="<?= site_url('users') ?>" class="btn btn-light">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?= $this->endSection() ?>

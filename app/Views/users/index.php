<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-end mb-3">
    <a href="<?= site_url('users/new') ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Novo usuário</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>Nome</th>
                <th>E-mail</th>
                <th>Papel</th>
                <th>Status</th>
                <th>Último login</th>
                <th class="text-end">Ações</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= esc($user['name']) ?></td>
                    <td><?= esc($user['email']) ?></td>
                    <td><?= badge(role_label($user['role']), $user['role'] === 'admin' ? 'dark' : 'info') ?></td>
                    <td><?= active_badge($user['active']) ?></td>
                    <td class="small"><?= format_datetime($user['last_login_at'], 'Nunca') ?></td>
                    <td class="text-end">
                        <a href="<?= site_url('users/' . $user['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary" title="Editar"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php if ($users === []): ?>
        <?= $this->include('partials/empty_state') ?>
    <?php endif ?>
    <?= $this->include('partials/pagination') ?>
</div>
<?= $this->endSection() ?>

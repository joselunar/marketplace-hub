<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <i class="bi bi-shield-lock display-4 text-danger"></i>
        <h2 class="h4 mt-3">Acesso negado</h2>
        <p class="text-body-secondary mb-4">Seu perfil não tem permissão para acessar esta área.</p>
        <a href="<?= site_url('dashboard') ?>" class="btn btn-primary">Voltar ao dashboard</a>
    </div>
</div>
<?= $this->endSection() ?>

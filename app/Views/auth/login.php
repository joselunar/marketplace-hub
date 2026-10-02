<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="text-center mb-4">
    <i class="bi bi-hexagon-fill text-primary display-5"></i>
    <h1 class="h4 mt-2 mb-0">Marketplace Hub</h1>
    <p class="text-body-secondary small">Acesso administrativo</p>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-4">
        <?= $this->include('partials/flash') ?>

        <form action="<?= site_url('login') ?>" method="post" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="email" class="form-label">E-mail</label>
                <input type="email" class="form-control" id="email" name="email" value="<?= esc(old('email') ?? '', 'attr') ?>" required autofocus autocomplete="username">
            </div>
            <div class="mb-4">
                <label for="password" class="form-label">Senha</label>
                <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-box-arrow-in-right me-1"></i> Entrar
            </button>
        </form>
    </div>
</div>
<?= $this->endSection() ?>

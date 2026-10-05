<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token-name" content="<?= esc(csrf_token(), 'attr') ?>">
    <meta name="csrf-token-hash" content="<?= esc(csrf_hash(), 'attr') ?>">
    <meta name="csrf-header" content="<?= esc(csrf_header(), 'attr') ?>">
    <title><?= esc($title ?? 'Painel') ?> · Vitrine Hub</title>
    <?= $this->include('partials/head_brand') ?>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body class="app">
<?= $this->include('partials/sidebar') ?>

<div class="app-main">
    <header class="app-header navbar bg-white border-bottom px-3 px-lg-4">
        <button class="btn btn-link text-body d-lg-none me-2 p-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Abrir menu">
            <i class="bi bi-list fs-3"></i>
        </button>
        <h1 class="h5 mb-0 me-auto text-truncate"><?= esc($title ?? '') ?></h1>

        <?php $user = auth_user(); ?>
        <?php if ($user !== null): ?>
            <div class="dropdown">
                <button class="btn btn-light btn-sm dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle"></i>
                    <span class="d-none d-sm-inline"><?= esc($user['name']) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li><span class="dropdown-item-text small text-body-secondary"><?= esc($user['email']) ?><br><?= esc(role_label($user['role'])) ?></span></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form action="<?= site_url('logout') ?>" method="post" class="px-3">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-1"></i>Sair</button>
                        </form>
                    </li>
                </ul>
            </div>
        <?php endif ?>
    </header>

    <main class="app-content p-3 p-lg-4">
        <?php if (! empty($breadcrumbs)): ?>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb small">
                    <?php foreach ($breadcrumbs as $label => $url): ?>
                        <?php if ($url === null): ?>
                            <li class="breadcrumb-item active" aria-current="page"><?= esc($label) ?></li>
                        <?php else: ?>
                            <li class="breadcrumb-item"><a href="<?= site_url($url) ?>"><?= esc($label) ?></a></li>
                        <?php endif ?>
                    <?php endforeach ?>
                </ol>
            </nav>
        <?php endif ?>

        <?= $this->include('partials/flash') ?>
        <?= $this->renderSection('content') ?>
    </main>
</div>

<?= $this->include('partials/confirm_modal') ?>

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>

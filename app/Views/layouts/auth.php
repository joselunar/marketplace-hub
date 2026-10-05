<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title ?? 'Entrar') ?> · Vitrine Hub</title>
    <meta name="description" content="Gestão de produtos, fornecedores e marketplaces em um só lugar.">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Vitrine Hub">
    <meta property="og:title" content="Vitrine Hub">
    <meta property="og:description" content="Gestão de produtos, fornecedores e marketplaces em um só lugar.">
    <meta property="og:image" content="<?= base_url('assets/brand/og-image.jpg') ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <?= $this->include('partials/head_brand') ?>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body class="auth-page d-flex align-items-center min-vh-100">
<main class="container">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-9 col-md-6 col-lg-4">
            <?= $this->renderSection('content') ?>
        </div>
    </div>
</main>
<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>

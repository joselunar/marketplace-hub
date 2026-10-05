<?php
/** @var \Config\Store $store */
$pageTitle   = isset($pageTitle) ? $pageTitle . ' · ' . $store->name : $store->name . ' · Variedades com a segurança do Mercado Livre';
$description = 'Loja de variedades com produtos para casa, eletrônicos, ferramentas, beleza, pet e esportes. Compra protegida pelo Mercado Livre e atendimento por WhatsApp e e-mail.';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($pageTitle) ?></title>
    <meta name="description" content="<?= esc($description, 'attr') ?>">
    <link rel="canonical" href="<?= esc(current_url(), 'attr') ?>">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="pt_BR">
    <meta property="og:site_name" content="<?= esc($store->name, 'attr') ?>">
    <meta property="og:title" content="<?= esc($pageTitle, 'attr') ?>">
    <meta property="og:description" content="<?= esc($description, 'attr') ?>">
    <meta property="og:url" content="<?= esc(current_url(), 'attr') ?>">
    <meta property="og:image" content="<?= base_url('assets/brand/og-image.jpg') ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <?= $this->include('partials/head_brand') ?>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/store.css') ?>">
    <script type="application/ld+json"><?= json_encode([
        '@context'     => 'https://schema.org',
        '@type'        => 'Organization',
        'name'         => $store->name,
        'legalName'    => $store->legalName,
        'taxID'        => $store->cnpj,
        'url'          => base_url(),
        'logo'         => base_url('assets/brand/logo-mark.png'),
        'email'        => $store->email,
        'telephone'    => '+' . $store->whatsapp,
        'address'      => ['@type' => 'PostalAddress', 'addressLocality' => 'Mossoró', 'addressRegion' => 'RN', 'addressCountry' => 'BR'],
        'contactPoint' => [['@type' => 'ContactPoint', 'contactType' => 'customer service', 'email' => $store->email, 'telephone' => '+' . $store->whatsapp, 'availableLanguage' => 'Portuguese']],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
</head>
<body class="store">

<header class="store-header sticky-top">
    <nav class="navbar navbar-expand-lg bg-white border-bottom">
        <div class="container">
            <a class="navbar-brand" href="<?= site_url('/') ?>">
                <img src="<?= base_url('assets/brand/logo-horizontal.png') ?>" alt="<?= esc($store->name, 'attr') ?>" width="160" height="35">
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#storeNav" aria-controls="storeNav" aria-expanded="false" aria-label="Abrir menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="storeNav">
                <ul class="navbar-nav ms-auto me-lg-3">
                    <li class="nav-item"><a class="nav-link" href="<?= site_url('/') ?>#categorias">Categorias</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= site_url('/') ?>#como-comprar">Como comprar</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= site_url('/') ?>#duvidas">Dúvidas</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= site_url('/') ?>#contato">Contato</a></li>
                </ul>
                <a class="btn btn-store-primary" href="<?= esc($store->shopUrl(), 'attr') ?>" target="_blank" rel="noopener">
                    <i class="bi bi-bag-check me-1"></i> Ver produtos
                </a>
            </div>
        </div>
    </nav>
</header>

<main>
    <?= $this->renderSection('content') ?>
</main>

<footer class="store-footer">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <img src="<?= base_url('assets/brand/logo-horizontal-white.png') ?>" alt="<?= esc($store->name, 'attr') ?>" width="160" height="40" class="mb-3">
                <p class="small mb-0">Loja de variedades com vendas e pagamentos feitos pelo Mercado Livre, com a proteção da Compra Garantida.</p>
            </div>
            <div class="col-6 col-lg-2">
                <h2 class="store-footer-title">Loja</h2>
                <ul class="list-unstyled small">
                    <li><a href="<?= site_url('/') ?>#categorias">Categorias</a></li>
                    <li><a href="<?= site_url('/') ?>#como-comprar">Como comprar</a></li>
                    <li><a href="<?= site_url('/') ?>#duvidas">Dúvidas frequentes</a></li>
                </ul>
            </div>
            <div class="col-6 col-lg-2">
                <h2 class="store-footer-title">Políticas</h2>
                <ul class="list-unstyled small">
                    <li><a href="<?= site_url('trocas-e-devolucoes') ?>">Trocas e devoluções</a></li>
                    <li><a href="<?= site_url('privacidade') ?>">Privacidade</a></li>
                </ul>
            </div>
            <div class="col-lg-4">
                <h2 class="store-footer-title">Atendimento</h2>
                <ul class="list-unstyled small">
                    <li><i class="bi bi-whatsapp me-2"></i><a href="<?= esc($store->whatsappLink(), 'attr') ?>" target="_blank" rel="noopener"><?= esc($store->whatsappDisplay()) ?></a></li>
                    <li><i class="bi bi-envelope me-2"></i><a href="mailto:<?= esc($store->email, 'attr') ?>"><?= esc($store->email) ?></a></li>
                    <li><i class="bi bi-clock me-2"></i><?= esc($store->hours) ?></li>
                </ul>
            </div>
        </div>
        <hr class="store-footer-divider">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-2 small">
            <span><?= esc($store->name) ?> · <?= esc($store->legalName) ?> · CNPJ <?= esc($store->cnpj) ?> · <?= esc($store->city) ?></span>
            <span>© <?= date('Y') ?> <?= esc($store->name) ?>. Mercado Livre e Mercado Pago são marcas de seus respectivos titulares.</span>
        </div>
    </div>
</footer>

<a class="store-whatsapp-float" href="<?= esc($store->whatsappLink(), 'attr') ?>" target="_blank" rel="noopener" aria-label="Falar com a Vitrine Hub no WhatsApp">
    <i class="bi bi-whatsapp"></i>
</a>

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>

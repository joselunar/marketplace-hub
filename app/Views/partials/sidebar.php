<?php
$navigation = config('Navigation');
$user       = auth_user();
?>
<aside class="offcanvas-lg offcanvas-start app-sidebar text-white" tabindex="-1" id="sidebar" aria-labelledby="sidebarLabel">
    <div class="offcanvas-header border-bottom border-secondary-subtle">
        <span class="offcanvas-title" id="sidebarLabel">
            <img src="<?= base_url('assets/brand/logo-horizontal-white.png') ?>" alt="Vitrine Hub" width="128" height="32" class="app-brand-logo">
        </span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Fechar"></button>
    </div>

    <div class="offcanvas-body d-flex flex-column p-0">
        <a href="<?= site_url('dashboard') ?>" class="app-brand d-none d-lg-flex align-items-center gap-2 px-3 text-white text-decoration-none">
            <img src="<?= base_url('assets/brand/logo-horizontal-white.png') ?>" alt="Vitrine Hub" width="144" height="36" class="app-brand-logo">
        </a>

        <nav class="flex-grow-1 overflow-auto py-2">
            <?php foreach ($navigation->sections as $section): ?>
                <?php
                $items = array_filter($section['items'], static fn ($item) => empty($item['roles']) || ($user !== null && in_array($user['role'], $item['roles'], true)));
                if ($items === []) {
                    continue;
                }
                ?>
                <?php if ($section['label'] !== ''): ?>
                    <div class="app-nav-section"><?= esc($section['label']) ?></div>
                <?php endif ?>
                <ul class="nav flex-column">
                    <?php foreach ($items as $item): ?>
                        <li class="nav-item">
                            <?php if (! empty($item['soon'])): ?>
                                <span class="nav-link app-nav-link disabled" title="Disponível na <?= esc($item['soon'], 'attr') ?>">
                                    <i class="bi bi-<?= esc($item['icon'], 'attr') ?>"></i>
                                    <span><?= esc($item['label']) ?></span>
                                    <span class="badge text-bg-dark ms-auto fw-normal"><?= esc($item['soon']) ?></span>
                                </span>
                            <?php else: ?>
                                <a href="<?= site_url($item['url']) ?>" class="nav-link app-nav-link <?= isset($item['exclude']) && nav_active($item['exclude']) !== '' ? '' : nav_active($item['prefix']) ?>">
                                    <i class="bi bi-<?= esc($item['icon'], 'attr') ?>"></i>
                                    <span><?= esc($item['label']) ?></span>
                                </a>
                            <?php endif ?>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endforeach ?>
        </nav>
    </div>
</aside>

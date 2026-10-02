<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'accounts' ? ' active' : '' ?>" href="<?= site_url('marketplaces/mercadolivre') ?>"><i class="bi bi-person-badge me-1"></i> Contas conectadas</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'categories' ? ' active' : '' ?>" href="<?= site_url('marketplaces/mercadolivre/categories') ?>"><i class="bi bi-diagram-3 me-1"></i> Categorias e atributos</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'listings' ? ' active' : '' ?>" href="<?= site_url('marketplaces/mercadolivre/listings') ?>"><i class="bi bi-megaphone me-1"></i> Anúncios</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'notifications' ? ' active' : '' ?>" href="<?= site_url('marketplaces/mercadolivre/notifications') ?>"><i class="bi bi-bell me-1"></i> Notificações</a>
    </li>
</ul>

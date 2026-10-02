<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'accounts' ? ' active' : '' ?>" href="<?= site_url('marketplaces/mercadolivre') ?>"><i class="bi bi-person-badge me-1"></i> Contas conectadas</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'categories' ? ' active' : '' ?>" href="<?= site_url('marketplaces/mercadolivre/categories') ?>"><i class="bi bi-diagram-3 me-1"></i> Categorias e atributos</a>
    </li>
</ul>

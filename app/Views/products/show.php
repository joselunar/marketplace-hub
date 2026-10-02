<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 justify-content-end mb-3">
    <a href="<?= site_url('offers/new?product_id=' . $product['id'] . '&return_to=product') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-plus-lg me-1"></i> Adicionar oferta</a>
    <a href="<?= site_url('products/' . $product['id'] . '/edit') ?>" class="btn btn-primary btn-sm"><i class="bi bi-pencil me-1"></i> Editar</a>
    <?php if (is_admin()): ?>
        <form action="<?= site_url('products/' . $product['id'] . '/delete') ?>" method="post" data-confirm="Excluir o produto <?= esc($product['sku'], 'attr') ?>? As ofertas vinculadas ficarão sem vínculo.">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
        </form>
    <?php endif ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-xl-8">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex gap-3">
                    <?php if ($images !== []): ?>
                        <img src="<?= esc($images[0]['url'], 'attr') ?>" alt="" class="product-thumb flex-shrink-0" loading="lazy" referrerpolicy="no-referrer">
                    <?php endif ?>
                    <div>
                        <div class="mb-1"><?= active_badge($product['active']) ?> <code class="ms-1"><?= esc($product['sku']) ?></code></div>
                        <h2 class="h5 mb-1"><?= esc($product['name']) ?></h2>
                        <div class="small text-body-secondary">
                            <?= esc($product['brand'] ?: 'Sem marca') ?> · <?= esc($category['name'] ?? 'Sem categoria') ?>
                            <?php if ($product['ean']): ?> · EAN <?= esc($product['ean']) ?><?php endif ?>
                        </div>
                    </div>
                </div>
                <?php if ($product['description']): ?>
                    <hr>
                    <div class="small" style="white-space: pre-line"><?= esc($product['description']) ?></div>
                <?php endif ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Logística</div>
            <div class="card-body">
                <dl class="dl-compact row mb-0">
                    <dt class="col-6">Peso</dt><dd class="col-6"><?= $product['weight'] !== null ? decimal_br($product['weight'], 3) . ' kg' : '—' ?></dd>
                    <dt class="col-6">Altura</dt><dd class="col-6"><?= $product['height'] !== null ? decimal_br($product['height']) . ' cm' : '—' ?></dd>
                    <dt class="col-6">Largura</dt><dd class="col-6"><?= $product['width'] !== null ? decimal_br($product['width']) . ' cm' : '—' ?></dd>
                    <dt class="col-6">Comprimento</dt><dd class="col-6"><?= $product['length'] !== null ? decimal_br($product['length']) . ' cm' : '—' ?></dd>
                    <dt class="col-6">Imagens</dt><dd class="col-6"><?= count($images) ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Ofertas dos fornecedores</span>
        <span class="small text-body-secondary fw-normal">Score e fornecedor selecionado serão calculados pelo motor de seleção (Fase 3).</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>Fornecedor</th>
                <th>SKU fornecedor</th>
                <th class="text-end">Custo</th>
                <th class="text-center">Estoque</th>
                <th class="text-end">Frete</th>
                <th class="text-center">Prazo</th>
                <th>Última atualização</th>
                <th>Status</th>
                <th class="text-center">Score</th>
                <th class="text-center">Selecionado</th>
                <th class="text-end">Ações</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($offers as $offer): ?>
                <?php $days = $offer['shipping_days'] ?? $offer['default_shipping_days']; ?>
                <tr>
                    <td>
                        <a href="<?= site_url('suppliers/' . $offer['supplier_id']) ?>" class="text-decoration-none"><?= esc($offer['supplier_name']) ?></a>
                        <?php if ($offer['supplier_status'] !== 'active'): ?>
                            <div><?= supplier_status_badge($offer['supplier_status']) ?></div>
                        <?php endif ?>
                    </td>
                    <td class="small"><code><?= esc($offer['supplier_sku']) ?></code></td>
                    <td class="text-end text-nowrap"><?= money($offer['cost_price']) ?></td>
                    <td class="text-center"><?= stock_badge($offer['stock_quantity']) ?></td>
                    <td class="text-end text-nowrap small"><?= money($offer['shipping_cost']) ?></td>
                    <td class="text-center small"><?= $days !== null ? (int) $days . ' d' : '—' ?></td>
                    <td class="small"><?= format_datetime($offer['last_sync_at'] ?? $offer['updated_at']) ?></td>
                    <td><?= active_badge($offer['active']) ?></td>
                    <td class="text-center text-body-secondary">—</td>
                    <td class="text-center text-body-secondary">—</td>
                    <td class="text-end text-nowrap">
                        <a href="<?= site_url('offers/' . $offer['id'] . '/edit?return_to=product') ?>" class="btn btn-sm btn-outline-secondary" title="Editar"><i class="bi bi-pencil"></i></a>
                        <form action="<?= site_url('offers/' . $offer['id'] . '/link') ?>" method="post" class="d-inline" data-confirm="Desvincular esta oferta do produto?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="product_id" value="">
                            <button type="submit" class="btn btn-sm btn-outline-warning" title="Desvincular"><i class="bi bi-link-45deg"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php if ($offers === []): ?>
        <div class="text-center text-body-secondary py-4">
            <i class="bi bi-exclamation-diamond display-6 d-block mb-2 text-warning"></i>
            Este produto ainda não possui ofertas de fornecedores.
            <div class="mt-2"><a href="<?= site_url('offers/new?product_id=' . $product['id'] . '&return_to=product') ?>" class="btn btn-sm btn-primary">Adicionar oferta</a></div>
        </div>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

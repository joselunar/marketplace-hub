<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?= $this->include('mercadolivre/_tabs') ?>

<?php if ($accounts === []): ?>
    <div class="card">
        <?= view('partials/empty_state', ['icon' => 'plug', 'message' => 'Conecte uma conta do Mercado Livre para consultar categorias e atributos.']) ?>
        <div class="text-center pb-4"><a href="<?= site_url('marketplaces/mercadolivre') ?>" class="btn btn-sm btn-primary">Ir para contas conectadas</a></div>
    </div>
<?php else: ?>
    <?php
    $accountId   = $account['id'] ?? null;
    $categoryUrl = static fn (string $id): string => site_url('marketplaces/mercadolivre/categories') . '?' . http_build_query(array_filter(['account' => count($accounts) > 1 ? $accountId : null, 'category' => $id]));
    ?>
    <div class="card filters-card mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <?php if (count($accounts) > 1): ?>
                    <div class="col-sm-4 col-lg-3">
                        <label class="form-label" for="account">Conta</label>
                        <select class="form-select form-select-sm" id="account" name="account">
                            <?php foreach ($accounts as $option): ?>
                                <option value="<?= (int) $option['id'] ?>" <?= (int) $option['id'] === (int) $accountId ? 'selected' : '' ?>><?= esc($option['name']) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                <?php endif ?>
                <div class="col-sm-8 col-lg-6">
                    <label class="form-label" for="q">Sugerir categoria pelo título do produto</label>
                    <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>" placeholder="Ex.: Fone de ouvido bluetooth sem fio" maxlength="150">
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-search"></i> Sugerir</button>
                    <a class="btn btn-sm btn-light" href="<?= site_url('marketplaces/mercadolivre/categories') ?>">Árvore completa</a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-1"></i> <?= esc($error) ?></div>
    <?php endif ?>

    <?php if ($predictions !== null): ?>
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">Sugestões para "<?= esc($filters['q']) ?>"</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($predictions as $prediction): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between flex-wrap gap-2">
                            <div>
                                <a href="<?= esc($categoryUrl($prediction['category_id']), 'attr') ?>" class="fw-semibold text-decoration-none"><?= esc($prediction['category_name']) ?></a>
                                <span class="small text-body-secondary ms-1"><?= esc($prediction['category_id']) ?></span>
                                <div class="small text-body-secondary">Domínio: <?= esc($prediction['domain_name']) ?> (<?= esc($prediction['domain_id']) ?>)</div>
                            </div>
                        </div>
                        <?php if ($prediction['attributes'] !== []): ?>
                            <div class="small mt-1">
                                <?php foreach ($prediction['attributes'] as $attribute): ?>
                                    <span class="badge text-bg-light border me-1"><?= esc($attribute['name']) ?>: <?= esc($attribute['value_name']) ?></span>
                                <?php endforeach ?>
                            </div>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
                <?php if ($predictions === []): ?>
                    <li class="list-group-item small text-body-secondary">Nenhuma sugestão para esse título.</li>
                <?php endif ?>
            </ul>
        </div>
    <?php endif ?>

    <?php if ($category !== null): ?>
        <nav aria-label="Caminho da categoria" class="mb-2">
            <ol class="breadcrumb small mb-0">
                <li class="breadcrumb-item"><a href="<?= site_url('marketplaces/mercadolivre/categories') . (count($accounts) > 1 ? '?account=' . (int) $accountId : '') ?>">Todas</a></li>
                <?php foreach ($category['path'] as $node): ?>
                    <?php if ($node['id'] === $category['id']): ?>
                        <li class="breadcrumb-item active" aria-current="page"><?= esc($node['name']) ?></li>
                    <?php else: ?>
                        <li class="breadcrumb-item"><a href="<?= esc($categoryUrl($node['id']), 'attr') ?>"><?= esc($node['name']) ?></a></li>
                    <?php endif ?>
                <?php endforeach ?>
            </ol>
        </nav>

        <div class="row g-3">
            <div class="col-12 col-xl-4">
                <div class="card">
                    <div class="card-header bg-white fw-semibold"><?= esc($category['name']) ?> <span class="small text-body-secondary"><?= esc($category['id']) ?></span></div>
                    <div class="card-body">
                        <?php $settings = $category['settings']; ?>
                        <dl class="dl-compact mb-0 small">
                            <dt>Aceita anúncios</dt>
                            <dd>
                                <?php if ($settings['listing_allowed'] === null): ?>—
                                <?php elseif ($settings['listing_allowed']): ?><?= badge('Sim', 'success') ?>
                                <?php else: ?><?= badge('Não (escolha uma subcategoria)', 'secondary') ?><?php endif ?>
                            </dd>
                            <dt>Status</dt><dd><?= esc($settings['status'] ?? '—') ?></dd>
                            <dt>Itens na categoria</dt><dd><?= $category['total_items'] !== null ? number_format($category['total_items'], 0, ',', '.') : '—' ?></dd>
                            <dt>Título (máx.)</dt><dd><?= $settings['max_title_length'] !== null ? (int) $settings['max_title_length'] . ' caracteres' : '—' ?></dd>
                            <dt>Fotos (máx.)</dt><dd><?= $settings['max_pictures_per_item'] ?? '—' ?></dd>
                            <dt>Condições</dt><dd><?= esc(implode(', ', $settings['item_conditions']) ?: '—') ?></dd>
                            <dt>Preço mínimo</dt><dd><?= $settings['minimum_price'] !== null ? money($settings['minimum_price']) : '—' ?></dd>
                            <dt>Domínio de catálogo</dt><dd><?= esc($settings['catalog_domain'] ?? '—') ?></dd>
                        </dl>
                    </div>
                    <?php if ($category['permalink']): ?>
                        <div class="card-footer bg-white small"><a href="<?= esc($category['permalink'], 'attr') ?>" target="_blank" rel="noopener noreferrer">Ver no Mercado Livre <i class="bi bi-box-arrow-up-right"></i></a></div>
                    <?php endif ?>
                </div>
            </div>

            <div class="col-12 col-xl-8">
                <?php if (! $category['is_leaf']): ?>
                    <div class="card">
                        <div class="card-header bg-white fw-semibold">Subcategorias (<?= count($category['children']) ?>)</div>
                        <div class="list-group list-group-flush">
                            <?php foreach ($category['children'] as $child): ?>
                                <a href="<?= esc($categoryUrl($child['id']), 'attr') ?>" class="list-group-item list-group-item-action d-flex justify-content-between">
                                    <span><?= esc($child['name']) ?> <span class="small text-body-secondary"><?= esc($child['id']) ?></span></span>
                                    <?php if ($child['total_items'] !== null): ?><span class="small text-body-secondary"><?= number_format($child['total_items'], 0, ',', '.') ?> itens</span><?php endif ?>
                                </a>
                            <?php endforeach ?>
                        </div>
                    </div>
                <?php else: ?>
                    <?php $requiredCount = count(array_filter($attributes, static fn (array $a): bool => $a['required'])); ?>
                    <div class="card">
                        <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                            <span>Atributos (<?= count($attributes) ?>)</span>
                            <span class="small"><?= badge($requiredCount . ' obrigatório(s)', $requiredCount > 0 ? 'danger' : 'secondary') ?></span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Atributo</th><th>Tipo</th><th>Grupo</th><th>Valores aceitos</th></tr></thead>
                                <tbody>
                                <?php foreach ($attributes as $attribute): ?>
                                    <tr>
                                        <td>
                                            <span class="fw-medium"><?= esc($attribute['name']) ?></span>
                                            <?php if ($attribute['required']): ?><?= badge('Obrigatório', 'danger') ?><?php endif ?>
                                            <div class="small text-body-secondary"><?= esc($attribute['id']) ?></div>
                                        </td>
                                        <td class="small"><?= esc($attribute['value_type']) ?><?= $attribute['value_max_length'] ? '<div class="text-body-secondary">até ' . (int) $attribute['value_max_length'] . '</div>' : '' ?></td>
                                        <td class="small"><?= esc($attribute['group'] ?? '—') ?></td>
                                        <td class="small">
                                            <?php if ($attribute['values'] !== []): ?>
                                                <?= esc(implode(', ', $attribute['values'])) ?><?= $attribute['values_count'] > count($attribute['values']) ? ' <span class="text-body-secondary">+' . ($attribute['values_count'] - count($attribute['values'])) . '</span>' : '' ?>
                                            <?php elseif ($attribute['units'] !== []): ?>
                                                Unidades: <?= esc(implode(', ', $attribute['units'])) ?>
                                            <?php else: ?>
                                                <span class="text-body-secondary">Livre</span>
                                            <?php endif ?>
                                        </td>
                                    </tr>
                                <?php endforeach ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($attributes === []): ?>
                            <div class="small text-body-secondary p-3">A categoria não informou atributos.</div>
                        <?php endif ?>
                    </div>
                <?php endif ?>
            </div>
        </div>
    <?php elseif ($roots !== []): ?>
        <div class="card">
            <div class="card-header bg-white fw-semibold">Categorias principais<?= $account ? ' · ' . esc($account['site_id'] ?: 'site padrão') : '' ?></div>
            <div class="card-body">
                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2">
                    <?php foreach ($roots as $root): ?>
                        <div class="col"><a href="<?= esc($categoryUrl($root['id']), 'attr') ?>" class="d-block border rounded px-3 py-2 text-decoration-none"><?= esc($root['name']) ?> <span class="small text-body-secondary"><?= esc($root['id']) ?></span></a></div>
                    <?php endforeach ?>
                </div>
            </div>
        </div>
    <?php endif ?>
<?php endif ?>
<?= $this->endSection() ?>

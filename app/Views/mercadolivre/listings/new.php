<?php

use App\Models\MarketplaceListingModel;

/** @var \App\DTO\MercadoLivre\ListingPreview|null $preview */
?>
<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?= $this->include('mercadolivre/_tabs') ?>

<?php if ($preview === null): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-1"></i> <?= esc($error ?? 'Não foi possível montar a prévia.') ?></div>
    <?php if ($accounts === []): ?>
        <a href="<?= site_url('marketplaces/mercadolivre') ?>" class="btn btn-sm btn-primary">Conectar uma conta do Mercado Livre</a>
    <?php endif ?>
<?php else: ?>
    <?php
    $product   = $preview->product;
    $isUp      = $preview->sellingModel === MarketplaceListingModel::MODEL_USER_PRODUCTS;
    $breakdown = $preview->breakdown;
    $fee       = $preview->fee;
    $offer     = $preview->offer;
    $sources   = ['form' => 'escolhida por você', 'listing' => 'do anúncio anterior', 'mapping' => 'mapeada da categoria interna', 'predictor' => 'sugerida pelo preditor do Mercado Livre'];
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h2 class="h5 mb-1"><?= esc($product['name']) ?></h2>
            <div class="small text-body-secondary">
                <code><?= esc($product['sku']) ?></code> · Conta <?= esc($preview->account['name']) ?> ·
                Modelo <?= badge(MarketplaceListingModel::MODEL_LABELS[$preview->sellingModel], $isUp ? 'info' : 'secondary') ?>
            </div>
        </div>
        <a href="<?= site_url('products/' . $product['id']) ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> Voltar ao produto</a>
    </div>

    <?php if ($preview->errors !== []): ?>
        <div class="alert alert-danger">
            <div class="fw-semibold mb-1"><i class="bi bi-x-octagon me-1"></i> Resolva antes de publicar:</div>
            <ul class="mb-0 small">
                <?php foreach ($preview->errors as $message): ?><li><?= esc($message) ?></li><?php endforeach ?>
            </ul>
        </div>
    <?php endif ?>
    <?php if ($preview->warnings !== []): ?>
        <div class="alert alert-warning">
            <ul class="mb-0 small">
                <?php foreach ($preview->warnings as $message): ?><li><?= esc($message) ?></li><?php endforeach ?>
            </ul>
        </div>
    <?php endif ?>

    <div class="row g-3">
        <div class="col-12 col-xl-7">
            <form method="get" action="<?= site_url('marketplaces/mercadolivre/listings/new') ?>" class="card mb-3" data-listing-preview>
                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                <div class="card-header bg-white fw-semibold">1. Dados do anúncio</div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php if (count($accounts) > 1): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="account">Conta</label>
                                <select class="form-select form-select-sm" id="account" name="account">
                                    <?php foreach ($accounts as $option): ?>
                                        <option value="<?= (int) $option['id'] ?>" <?= (int) $option['id'] === (int) $preview->account['id'] ? 'selected' : '' ?>><?= esc($option['name']) ?></option>
                                    <?php endforeach ?>
                                </select>
                            </div>
                        <?php endif ?>

                        <div class="col-md-6">
                            <label class="form-label" for="category_id">Categoria do Mercado Livre</label>
                            <input type="text" class="form-control form-control-sm" id="category_id" name="category_id" value="<?= esc($preview->category['id'] ?? '', 'attr') ?>" placeholder="MLB1234" maxlength="20">
                            <div class="form-text">
                                <?php if ($preview->category !== null): ?>
                                    <?= esc(implode(' › ', array_column($preview->category['path'], 'name')) ?: $preview->category['name']) ?>
                                    <?php if ($preview->categorySource !== null): ?>(<?= esc($sources[$preview->categorySource] ?? $preview->categorySource) ?>)<?php endif ?>
                                <?php else: ?>
                                    <a href="<?= site_url('marketplaces/mercadolivre/categories') ?>" target="_blank">Consultar categorias</a>
                                <?php endif ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="listing_type_id">Tipo de anúncio</label>
                            <select class="form-select form-select-sm" id="listing_type_id" name="listing_type_id" <?= $preview->listingTypes === [] ? 'disabled' : '' ?>>
                                <?php foreach ($preview->listingTypes as $type): ?>
                                    <option value="<?= esc($type['id'], 'attr') ?>" <?= $type['id'] === $preview->listingTypeId ? 'selected' : '' ?>>
                                        <?= esc($type['name']) ?> (<?= esc($type['id']) ?>)<?= $type['remaining_listings'] !== null ? ' · restam ' . (int) $type['remaining_listings'] : '' ?>
                                    </option>
                                <?php endforeach ?>
                            </select>
                            <div class="form-text">Tipos disponíveis para a conta nesta categoria.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="condition">Condição</label>
                            <select class="form-select form-select-sm" id="condition" name="condition">
                                <?php foreach (($preview->conditions ?: [$preview->condition]) as $condition): ?>
                                    <option value="<?= esc($condition, 'attr') ?>" <?= $condition === $preview->condition ? 'selected' : '' ?>><?= esc(['new' => 'Novo', 'used' => 'Usado', 'not_specified' => 'Não especificado'][$condition] ?? $condition) ?></option>
                                <?php endforeach ?>
                            </select>
                        </div>

                        <?php if ($isUp): ?>
                            <div class="col-12">
                                <label class="form-label" for="family_name">Nome da família (family_name)</label>
                                <input type="text" class="form-control form-control-sm" id="family_name" name="family_name" value="<?= esc($preview->familyName, 'attr') ?>" maxlength="255">
                                <div class="form-text">A conta publica no modelo User Products: o título é gerado pelo Mercado Livre a partir deste nome genérico e dos atributos (ex.: "Apple iPhone 256GB").</div>
                            </div>
                        <?php else: ?>
                            <div class="col-12">
                                <label class="form-label" for="title">Título</label>
                                <input type="text" class="form-control form-control-sm" id="title" name="title" value="<?= esc($preview->title, 'attr') ?>" maxlength="<?= (int) ($preview->maxTitleLength ?? 255) ?>">
                                <div class="form-text"><?= mb_strlen($preview->title) ?><?= $preview->maxTitleLength ? ' de ' . (int) $preview->maxTitleLength : '' ?> caracteres. Produto, marca e modelo; sem a palavra "estoque".</div>
                            </div>
                        <?php endif ?>

                        <?php if ($preview->attributes !== []): ?>
                            <div class="col-12"><div class="fw-semibold small border-bottom pb-1">Atributos</div></div>
                            <?php foreach ($preview->attributes as $attribute): ?>
                                <?php $fieldId = 'attr_' . strtolower($attribute['id']); ?>
                                <div class="col-md-6">
                                    <label class="form-label" for="<?= esc($fieldId, 'attr') ?>">
                                        <?= esc($attribute['name']) ?> <span class="text-body-secondary small"><?= esc($attribute['id']) ?></span>
                                        <?php if ($attribute['required']): ?><span class="text-danger">*</span><?php endif ?>
                                    </label>
                                    <input type="text" class="form-control form-control-sm" id="<?= esc($fieldId, 'attr') ?>" name="attributes[<?= esc($attribute['id'], 'attr') ?>]"
                                           value="<?= esc($preview->attributeValues[$attribute['id']] ?? '', 'attr') ?>" maxlength="<?= (int) ($attribute['value_max_length'] ?? 255) ?>"
                                           <?= $attribute['values'] !== [] ? 'list="' . esc($fieldId, 'attr') . '_values"' : '' ?>>
                                    <?php if ($attribute['values'] !== []): ?>
                                        <datalist id="<?= esc($fieldId, 'attr') ?>_values">
                                            <?php foreach ($attribute['values'] as $value): ?><option value="<?= esc($value, 'attr') ?>"><?php endforeach ?>
                                        </datalist>
                                    <?php endif ?>
                                </div>
                            <?php endforeach ?>
                        <?php endif ?>

                        <div class="col-12"><div class="fw-semibold small border-bottom pb-1">Garantia (opcional)</div></div>
                        <div class="col-md-6">
                            <label class="form-label" for="warranty_type">Tipo de garantia</label>
                            <input type="text" class="form-control form-control-sm" id="warranty_type" name="warranty_type" value="<?= esc($preview->warrantyType ?? '', 'attr') ?>" placeholder="Ex.: Garantia do vendedor" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="warranty_time">Tempo de garantia</label>
                            <input type="text" class="form-control form-control-sm" id="warranty_time" name="warranty_time" value="<?= esc($preview->warrantyTime ?? '', 'attr') ?>" placeholder="Ex.: 90 dias" maxlength="100">
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-white d-flex justify-content-end">
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise"></i> Atualizar prévia</button>
                </div>
            </form>

            <?php if ($preview->predictions !== []): ?>
                <div class="card mb-3">
                    <div class="card-header bg-white fw-semibold small">Categorias sugeridas pelo Mercado Livre para o nome do produto</div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($preview->predictions as $prediction): ?>
                            <a class="list-group-item list-group-item-action small" href="<?= site_url('marketplaces/mercadolivre/listings/new') . '?' . esc(http_build_query(['product_id' => $product['id'], 'account' => $preview->account['id'], 'category_id' => $prediction['category_id']]), 'attr') ?>">
                                <?= esc($prediction['category_name']) ?> <span class="text-body-secondary"><?= esc($prediction['category_id']) ?> · <?= esc($prediction['domain_name']) ?></span>
                            </a>
                        <?php endforeach ?>
                    </div>
                </div>
            <?php endif ?>

            <?php if ($preview->payload !== null): ?>
                <div class="card mb-3">
                    <div class="card-header bg-white fw-semibold">3. O que será enviado (POST /items)</div>
                    <div class="card-body">
                        <pre class="small bg-body-tertiary border rounded p-2 mb-2" style="max-height: 360px"><?= esc(json_encode($preview->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                        <div class="small text-body-secondary">
                            <?php if (trim((string) $product['description']) !== ''): ?>
                                A descrição do produto é enviada logo depois, em POST /items/{id}/description.
                            <?php else: ?>
                                O produto não tem descrição; o anúncio será criado sem ela.
                            <?php endif ?>
                        </div>
                    </div>
                </div>
            <?php endif ?>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card mb-3">
                <div class="card-header bg-white fw-semibold">2. Fornecedor, estoque e preço</div>
                <div class="card-body">
                    <dl class="dl-compact row small mb-0">
                        <dt class="col-6">Fornecedor principal</dt>
                        <dd class="col-6"><?= esc($offer?->supplierName ?? '—') ?></dd>
                        <dt class="col-6">Custo + frete</dt>
                        <dd class="col-6"><?= $offer ? money($offer->costPrice) . ' + ' . money($offer->shippingCost) : '—' ?></dd>
                        <dt class="col-6">Estoque do fornecedor</dt>
                        <dd class="col-6"><?= $offer ? (int) $offer->stockQuantity . ' (reserva ' . (int) $offer->safetyStock . ')' : '—' ?></dd>
                        <dt class="col-6">Estoque a publicar</dt>
                        <dd class="col-6 fw-semibold"><?= (int) $preview->quantity ?> un.</dd>
                    </dl>

                    <?php if ($breakdown !== null && $fee !== null): ?>
                        <hr>
                        <div class="d-flex justify-content-between align-items-baseline mb-2">
                            <span class="fw-semibold">Preço no Mercado Livre</span>
                            <span class="fs-4 fw-bold"><?= money($breakdown->price) ?></span>
                        </div>
                        <table class="table table-sm small mb-2">
                            <tbody>
                                <tr><td>Custo do produto</td><td class="text-end"><?= money($breakdown->costPrice) ?></td></tr>
                                <tr><td>Frete do fornecedor</td><td class="text-end"><?= money($breakdown->shippingCost) ?></td></tr>
                                <tr><td>Comissão ML (<?= percent_format($fee->percentageFee, 2) ?>)</td><td class="text-end"><?= money($breakdown->marketplaceFee) ?></td></tr>
                                <tr><td>Tarifa fixa ML</td><td class="text-end"><?= money($breakdown->marketplaceFixedFee) ?></td></tr>
                                <tr><td>Impostos e custos extras</td><td class="text-end"><?= money($breakdown->otherCosts()) ?></td></tr>
                                <tr class="fw-semibold"><td>Lucro</td><td class="text-end"><?= money($breakdown->profit) ?></td></tr>
                                <tr><td>Margem (mínima <?= percent_format($breakdown->margins->minimum) ?>)</td><td class="text-end"><?= margin_badge($breakdown->marginPercentage, $breakdown->isBelowMinimum() ? 'below_minimum' : 'ok') ?></td></tr>
                            </tbody>
                        </table>
                        <div class="small text-body-secondary">
                            Tarifa oficial consultada em <code>listing_prices</code> para <?= esc($fee->listingTypeName) ?> a <?= money($fee->price) ?>:
                            tarifa de venda <?= money($fee->saleFeeAmount) ?><?= bccomp($fee->listingFeeAmount, '0', 2) > 0 ? ', custo de anunciar ' . money($fee->listingFeeAmount) : '' ?>.
                            <?= $breakdown->isManual() ? 'Preço manual do produto.' : 'Preço calculado para a margem desejada com essa tarifa.' ?>
                        </div>
                    <?php endif ?>
                </div>
            </div>

            <?php if ($preview->pictures !== []): ?>
                <div class="card mb-3">
                    <div class="card-header bg-white fw-semibold small">Imagens (<?= count($preview->pictures) ?>)</div>
                    <div class="card-body d-flex flex-wrap gap-2">
                        <?php foreach ($preview->pictures as $url): ?>
                            <img src="<?= esc($url, 'attr') ?>" alt="" class="product-thumb" loading="lazy" referrerpolicy="no-referrer">
                        <?php endforeach ?>
                    </div>
                </div>
            <?php endif ?>

            <form method="post" action="<?= site_url('marketplaces/mercadolivre/listings') ?>" class="card border-primary" data-confirm="Publicar este anúncio no Mercado Livre agora?">
                <?= csrf_field() ?>
                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                <input type="hidden" name="account" value="<?= (int) $preview->account['id'] ?>">
                <?php if ($preview->draft !== null): ?>
                    <input type="hidden" name="category_id" value="<?= esc($preview->draft->categoryId, 'attr') ?>">
                    <input type="hidden" name="listing_type_id" value="<?= esc($preview->draft->listingTypeId, 'attr') ?>">
                    <input type="hidden" name="condition" value="<?= esc($preview->draft->condition, 'attr') ?>">
                    <input type="hidden" name="title" value="<?= esc($preview->title, 'attr') ?>">
                    <input type="hidden" name="family_name" value="<?= esc($preview->familyName, 'attr') ?>">
                    <input type="hidden" name="warranty_type" value="<?= esc($preview->warrantyType ?? '', 'attr') ?>">
                    <input type="hidden" name="warranty_time" value="<?= esc($preview->warrantyTime ?? '', 'attr') ?>">
                    <?php foreach ($preview->attributeValues as $id => $value): ?>
                        <input type="hidden" name="attributes[<?= esc($id, 'attr') ?>]" value="<?= esc($value, 'attr') ?>">
                    <?php endforeach ?>
                    <input type="hidden" name="confirm_price" value="<?= esc($preview->price(), 'attr') ?>">
                    <input type="hidden" name="confirm_quantity" value="<?= (int) $preview->quantity ?>">
                <?php endif ?>
                <div class="card-body">
                    <div class="fw-semibold mb-1">4. Confirmação</div>
                    <p class="small text-body-secondary mb-2">
                        Nada foi enviado ainda. Ao confirmar, o anúncio é criado na conta <strong><?= esc($preview->account['name']) ?></strong>
                        <?php if ($preview->draft !== null): ?>por <strong><?= money($preview->price()) ?></strong> com <strong><?= (int) $preview->quantity ?></strong> unidade(s)<?php endif ?>.
                        Se o preço ou o estoque mudarem até lá, a publicação é bloqueada e a prévia precisa ser revista.
                    </p>
                    <div class="small text-warning-emphasis d-none mb-2" data-listing-stale><i class="bi bi-exclamation-triangle"></i> Você alterou os dados: clique em "Atualizar prévia" antes de confirmar.</div>
                    <button type="submit" class="btn btn-primary w-100" data-listing-confirm <?= $preview->canPublish() ? '' : 'disabled' ?>>
                        <i class="bi bi-send-check me-1"></i> Confirmar e publicar
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif ?>
<?= $this->endSection() ?>

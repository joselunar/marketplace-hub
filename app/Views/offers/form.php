<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$action = $offer === null ? site_url('offers') : site_url('offers/' . $offer['id']);
$value  = static fn (string $field, mixed $default = '') => old($field) ?? ($offer[$field] ?? $prefill[$field] ?? $default);
$active = old('active') ?? ($offer['active'] ?? 1);
$blocked = old('blocked') ?? ($offer['blocked'] ?? 0);
$moneyValue = static function (string $field) use ($offer): string {
    $old = old($field);

    if ($old !== null) {
        return (string) $old;
    }

    return isset($offer[$field]) ? decimal_br($offer[$field]) : '';
};
?>
<form action="<?= $action ?>" method="post" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="<?= esc($returnTo, 'attr') ?>">

    <div class="row g-3">
        <div class="col-12 col-xl-7">
            <div class="card">
                <div class="card-header bg-white fw-semibold">Oferta</div>
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <label for="supplier_id" class="form-label">Fornecedor *</label>
                        <select class="form-select<?= invalid_class('supplier_id') ?>" id="supplier_id" name="supplier_id" required>
                            <option value="">Selecione…</option>
                            <?php foreach ($suppliers as $id => $name): ?>
                                <option value="<?= $id ?>" <?= (string) $value('supplier_id') === (string) $id ? 'selected' : '' ?>><?= esc($name) ?></option>
                            <?php endforeach ?>
                        </select>
                        <div class="invalid-feedback"><?= esc(field_error('supplier_id') ?? '') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label for="supplier_sku" class="form-label">SKU no fornecedor *</label>
                        <input type="text" class="form-control<?= invalid_class('supplier_sku') ?>" id="supplier_sku" name="supplier_sku" value="<?= esc($value('supplier_sku'), 'attr') ?>" required maxlength="100">
                        <div class="invalid-feedback"><?= esc(field_error('supplier_sku') ?? '') ?></div>
                    </div>
                    <div class="col-md-8">
                        <label for="supplier_product_name" class="form-label">Nome no fornecedor *</label>
                        <input type="text" class="form-control<?= invalid_class('supplier_product_name') ?>" id="supplier_product_name" name="supplier_product_name" value="<?= esc($value('supplier_product_name'), 'attr') ?>" required maxlength="255">
                        <div class="invalid-feedback"><?= esc(field_error('supplier_product_name') ?? '') ?></div>
                    </div>
                    <div class="col-md-4">
                        <label for="ean" class="form-label">EAN no fornecedor</label>
                        <input type="text" class="form-control<?= invalid_class('ean') ?>" id="ean" name="ean" value="<?= esc($value('ean'), 'attr') ?>" maxlength="14" inputmode="numeric">
                        <div class="invalid-feedback"><?= esc(field_error('ean') ?? '') ?></div>
                    </div>

                    <div class="col-12">
                        <label for="product_search" class="form-label">Produto do catálogo</label>
                        <div class="position-relative" data-product-autocomplete data-endpoint="<?= site_url('api/products') ?>">
                            <div class="input-group">
                                <input type="search" class="form-control<?= invalid_class('product_id') ?>" id="product_search" autocomplete="off"
                                       placeholder="Digite SKU, EAN ou nome para buscar"
                                       value="<?= $product ? esc($product['sku'] . ' — ' . $product['name'], 'attr') : '' ?>">
                                <button class="btn btn-outline-secondary" type="button" data-autocomplete-clear title="Remover vínculo"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <input type="hidden" name="product_id" value="<?= esc((string) ($product['id'] ?? ''), 'attr') ?>">
                            <div class="list-group autocomplete-menu shadow-sm d-none"></div>
                            <div class="form-text">Opcional. Ofertas sem vínculo não entram no catálogo até serem associadas a um produto.</div>
                            <?php if (field_error('product_id')): ?>
                                <div class="text-danger small"><?= esc(field_error('product_id')) ?></div>
                            <?php endif ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card">
                <div class="card-header bg-white fw-semibold">Custo, estoque e envio</div>
                <div class="card-body row g-3">
                    <div class="col-6">
                        <label for="cost_price" class="form-label">Custo (R$) *</label>
                        <input type="text" inputmode="decimal" class="form-control<?= invalid_class('cost_price') ?>" id="cost_price" name="cost_price" value="<?= esc($moneyValue('cost_price'), 'attr') ?>" required placeholder="0,00">
                        <div class="invalid-feedback"><?= esc(field_error('cost_price') ?? '') ?></div>
                    </div>
                    <div class="col-6">
                        <label for="stock_quantity" class="form-label">Estoque informado *</label>
                        <input type="number" class="form-control<?= invalid_class('stock_quantity') ?>" id="stock_quantity" name="stock_quantity" min="0" value="<?= esc((string) $value('stock_quantity', 0), 'attr') ?>" required>
                        <div class="invalid-feedback"><?= esc(field_error('stock_quantity') ?? '') ?></div>
                    </div>
                    <div class="col-6">
                        <label for="shipping_cost" class="form-label">Frete do fornecedor (R$)</label>
                        <input type="text" inputmode="decimal" class="form-control<?= invalid_class('shipping_cost') ?>" id="shipping_cost" name="shipping_cost" value="<?= esc($moneyValue('shipping_cost'), 'attr') ?>" placeholder="0,00">
                        <div class="invalid-feedback"><?= esc(field_error('shipping_cost') ?? '') ?></div>
                    </div>
                    <div class="col-6">
                        <label for="shipping_days" class="form-label">Prazo de postagem (dias)</label>
                        <input type="number" class="form-control<?= invalid_class('shipping_days') ?>" id="shipping_days" name="shipping_days" min="0" max="365" value="<?= esc((string) $value('shipping_days'), 'attr') ?>">
                        <div class="form-text">Vazio = prazo padrão do fornecedor.</div>
                        <div class="invalid-feedback"><?= esc(field_error('shipping_days') ?? '') ?></div>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input type="hidden" name="active" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" <?= (int) $active === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="active">Oferta ativa</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input type="hidden" name="blocked" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="blocked" name="blocked" value="1" <?= (int) $blocked === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="blocked">Bloquear manualmente</label>
                        </div>
                        <div class="form-text">Ofertas bloqueadas nunca são selecionadas pelo motor de seleção.</div>
                    </div>
                    <div class="col-12">
                        <label for="blocked_reason" class="form-label">Motivo do bloqueio</label>
                        <input type="text" class="form-control<?= invalid_class('blocked_reason') ?>" id="blocked_reason" name="blocked_reason" value="<?= esc((string) $value('blocked_reason'), 'attr') ?>" maxlength="255" placeholder="Ex.: atrasos recorrentes na postagem">
                        <div class="invalid-feedback"><?= esc(field_error('blocked_reason') ?? '') ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Salvar</button>
        <a href="<?= $returnTo === 'product' && $product ? site_url('products/' . $product['id']) : site_url('offers') ?>" class="btn btn-light">Cancelar</a>
    </div>
</form>
<?= $this->endSection() ?>

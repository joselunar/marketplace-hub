<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php /** @var App\DTO\Pricing\PricingConfig $config */ ?>
<div class="row g-3">
    <div class="col-12 col-xl-5">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Dados da simulação</div>
            <div class="card-body">
                <form method="get" action="<?= site_url('pricing') ?>" data-pricing-simulator data-endpoint="<?= site_url('pricing/simulate') ?>" novalidate>
                    <div class="row g-3">
                        <?php
                        $fields = [
                            'cost_price'    => ['Custo do produto (R$) *', 'col-sm-4'],
                            'shipping_cost' => ['Frete do fornecedor (R$)', 'col-sm-4'],
                            'extra_cost'    => ['Custo extra (R$)', 'col-sm-4'],
                        ];
                        ?>
                        <?php foreach ($fields as $field => [$label, $col]): ?>
                            <div class="<?= $col ?>">
                                <label for="<?= $field ?>" class="form-label"><?= $label ?></label>
                                <input type="text" inputmode="decimal" class="form-control<?= isset($errors[$field]) ? ' is-invalid' : '' ?>" id="<?= $field ?>" name="<?= $field ?>" value="<?= esc($input[$field], 'attr') ?>">
                            </div>
                        <?php endforeach ?>

                        <div class="col-12">
                            <label for="category_id" class="form-label">Categoria</label>
                            <select class="form-select<?= isset($errors['category_id']) ? ' is-invalid' : '' ?>" id="category_id" name="category_id">
                                <option value="">— Usar margens globais —</option>
                                <?php foreach ($categories as $id => $label): ?>
                                    <option value="<?= $id ?>" <?= $input['category_id'] === (string) $id ? 'selected' : '' ?>><?= esc($label) ?></option>
                                <?php endforeach ?>
                            </select>
                            <div class="form-text">As margens vazias abaixo herdam desta categoria (ou da configuração global).</div>
                        </div>

                        <div class="col-sm-6">
                            <label for="desired_margin_percentage" class="form-label">Margem desejada (%)</label>
                            <input type="text" inputmode="decimal" class="form-control<?= isset($errors['desired_margin_percentage']) ? ' is-invalid' : '' ?>" id="desired_margin_percentage" name="desired_margin_percentage" value="<?= esc($input['desired_margin_percentage'], 'attr') ?>" placeholder="herdada">
                        </div>
                        <div class="col-sm-6">
                            <label for="minimum_margin_percentage" class="form-label">Margem mínima (%)</label>
                            <input type="text" inputmode="decimal" class="form-control<?= isset($errors['minimum_margin_percentage']) ? ' is-invalid' : '' ?>" id="minimum_margin_percentage" name="minimum_margin_percentage" value="<?= esc($input['minimum_margin_percentage'], 'attr') ?>" placeholder="herdada">
                        </div>
                        <div class="col-12">
                            <label for="sale_price" class="form-label">Preço de venda a testar (R$)</label>
                            <input type="text" inputmode="decimal" class="form-control<?= isset($errors['sale_price']) ? ' is-invalid' : '' ?>" id="sale_price" name="sale_price" value="<?= esc($input['sale_price'], 'attr') ?>" placeholder="vazio = calcular o preço sugerido">
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-calculator me-1"></i> Calcular</button>
                        <a href="<?= site_url('pricing') ?>" class="btn btn-light">Limpar</a>
                    </div>
                </form>
            </div>
            <div class="card-footer bg-white small text-body-secondary">
                Parâmetros atuais: comissão <?= percent_format($config->marketplaceFeePercentage, 2) ?>,
                tarifa fixa <?= money($config->marketplaceFixedFee) ?><?= bccomp($config->fixedFeeThreshold, '0', 2) > 0 ? ' abaixo de ' . money($config->fixedFeeThreshold) : '' ?>,
                impostos <?= percent_format($config->taxPercentage, 2) ?>,
                extras <?= percent_format($config->extraCostPercentage, 2) ?> + <?= money($config->extraFixedCost) ?>/un.,
                arredondamento: <?= esc(mb_strtolower($config->roundingLabel())) ?>.
                <?php if (is_admin()): ?><a href="<?= site_url('settings') ?>">Alterar</a><?php endif ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-7">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Resultado</div>
            <div class="card-body" data-pricing-result aria-live="polite">
                <?= $this->include('pricing/_result') ?>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?php

/**
 * @var list<array<string, mixed>>  $marketplaces
 * @var array<string, string>       $feeFields
 * @var App\DTO\Pricing\PricingConfig $global
 */

$failedSlug = session()->getFlashdata('fees_slug');
$globalFees = [
    'fee_percentage'      => $global->marketplaceFeePercentage,
    'fixed_fee'           => $global->marketplaceFixedFee,
    'fixed_fee_threshold' => $global->fixedFeeThreshold,
];
$formatFee = static fn (string $field, ?string $value): string => $value === null || $value === ''
    ? '—'
    : ($field === 'fee_percentage' ? percent_format($value, 2) : money($value));
$effective = static fn (array $fees): string => $formatFee('fee_percentage', $fees['fee_percentage']) . ' + ' . $formatFee('fixed_fee', $fees['fixed_fee'])
    . (bccomp((string) $fees['fixed_fee_threshold'], '0', 2) > 0 ? ' abaixo de ' . $formatFee('fixed_fee_threshold', $fees['fixed_fee_threshold']) : '');

?>
<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<p class="text-body-secondary small">
    Cada marketplace é integrado por um adaptador próprio, seguindo a API oficial dele. A automação (tokens, pedidos,
    notificações, estoque e preço) passa por todos os adaptadores que oferecem a operação.
</p>

<div class="row g-3">
    <?php foreach ($marketplaces as $marketplace): ?>
        <?php $slug = $marketplace['slug']; ?>
        <div class="col-12 col-xl-6">
            <div class="card h-100" id="marketplace-<?= esc($slug, 'attr') ?>">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><i class="bi bi-shop me-1"></i><?= esc($marketplace['name']) ?></span>
                    <?= $marketplace['configured'] ? badge('Integração configurada', 'success') : badge('Integração não configurada', 'warning') ?>
                </div>
                <div class="card-body">
                    <?php if (! $marketplace['configured']): ?>
                        <div class="alert alert-warning small py-2">
                            Preencha no <code>.env</code>: <?= esc(implode(', ', $marketplace['missing'])) ?>.
                        </div>
                    <?php endif ?>

                    <div class="row text-center g-2 mb-3">
                        <div class="col-4">
                            <div class="fs-5 fw-semibold"><?= (int) $marketplace['connected'] ?></div>
                            <div class="small text-body-secondary">conta(s) conectada(s)</div>
                        </div>
                        <div class="col-4">
                            <div class="fs-5 fw-semibold<?= $marketplace['reconnect'] > 0 ? ' text-danger' : '' ?>"><?= (int) $marketplace['reconnect'] ?></div>
                            <div class="small text-body-secondary">para reconectar</div>
                        </div>
                        <div class="col-4">
                            <div class="fs-5 fw-semibold"><?= (int) $marketplace['active_listings'] ?></div>
                            <div class="small text-body-secondary">anúncio(s) ativo(s)</div>
                        </div>
                    </div>

                    <div class="small mb-3">
                        <span class="text-body-secondary">Oferece:</span>
                        <?php foreach ($marketplace['capabilities'] as $label): ?>
                            <span class="badge text-bg-light border"><?= esc($label) ?></span>
                        <?php endforeach ?>
                    </div>

                    <div class="d-flex gap-2 mb-3">
                        <a href="<?= site_url($marketplace['accounts_path']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plug"></i> Contas</a>
                        <?php if ($marketplace['listings_path'] !== null): ?>
                            <a href="<?= site_url($marketplace['listings_path']) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-megaphone"></i> Anúncios</a>
                        <?php endif ?>
                    </div>

                    <h6 class="fw-semibold mb-2">Tarifas padrão</h6>
                    <p class="small text-body-secondary mb-2">
                        Usadas no preço sugerido e no lucro estimado quando o marketplace ainda não informou a tarifa real
                        (a cotação oficial do anúncio e a tarifa do pedido sempre prevalecem). Campo vazio herda o valor global.
                    </p>

                    <?php if (is_admin()): ?>
                        <form action="<?= site_url('marketplaces/' . $slug . '/fees') ?>" method="post" class="row g-2 align-items-end">
                            <?= csrf_field() ?>
                            <?php foreach ($feeFields as $field => $label): ?>
                                <?php
                                $stored = $marketplace['fees'][$field] ?? null;
                                $value  = $failedSlug === $slug ? (string) old($field) : ($stored === null ? '' : number_format((float) $stored, 2, ',', ''));
                                $error  = $failedSlug === $slug ? field_error($field) : null;
                                $id     = $slug . '-' . $field;
                                ?>
                                <div class="col-sm-4">
                                    <label class="form-label small" for="<?= esc($id, 'attr') ?>"><?= esc($label) ?></label>
                                    <input type="text" inputmode="decimal" class="form-control form-control-sm<?= $error !== null ? ' is-invalid' : '' ?>" id="<?= esc($id, 'attr') ?>" name="<?= esc($field, 'attr') ?>" value="<?= esc($value, 'attr') ?>" placeholder="global: <?= esc($formatFee($field, $globalFees[$field]), 'attr') ?>">
                                    <?php if ($error !== null): ?><div class="invalid-feedback"><?= esc($error) ?></div><?php endif ?>
                                </div>
                            <?php endforeach ?>
                            <div class="col-12 d-flex justify-content-between align-items-center">
                                <span class="small text-body-secondary">
                                    Em vigor: <?= $effective($marketplace['effective_fees']) ?>
                                </span>
                                <button type="submit" class="btn btn-sm btn-primary">Salvar tarifas</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="small">
                            Em vigor: <?= $effective($marketplace['effective_fees']) ?>.
                            Um administrador altera nesta tela.
                        </div>
                    <?php endif ?>
                </div>
            </div>
        </div>
    <?php endforeach ?>
</div>

<div class="card mt-3">
    <div class="card-body small text-body-secondary">
        <i class="bi bi-info-circle me-1"></i>
        Novos marketplaces entram por um adaptador que implementa a interface comum, registrado em <code>app/Config/Marketplaces.php</code>.
        O passo a passo está no README, em "Adicionando um marketplace".
    </div>
</div>
<?= $this->endSection() ?>

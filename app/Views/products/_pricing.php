<?php
/** @var App\DTO\Suppliers\SelectionResult $selection */
$primary   = $selection->primary();
$blocked   = $selection->blockedOnlyByMargin();
$reference = $primary ?? ($blocked[0] ?? null);
$breakdown = $reference?->pricing;
$context   = $selection->pricing;

$invalidReasons = [];

foreach ($selection->evaluations as $evaluation) {
    foreach ($evaluation->ineligibilityReasons as $reason) {
        if (str_starts_with($reason, 'Precificação inviável') && ! in_array($reason, $invalidReasons, true)) {
            $invalidReasons[] = $reason;
        }
    }
}
?>
<div class="card mb-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-currency-dollar me-1"></i> Precificação</span>
        <?php if ($breakdown !== null): ?>
            <?= $breakdown->isManual() ? badge('Preço fixo', 'secondary') : badge('Preço sugerido', 'primary') ?>
        <?php endif ?>
    </div>
    <div class="card-body">
        <?php if ($breakdown === null): ?>
            <p class="small text-body-secondary mb-0">
                <?php if ($selection->evaluations === []): ?>
                    Sem ofertas vinculadas: o preço depende do custo de um fornecedor.
                <?php elseif ($invalidReasons !== []): ?>
                    <i class="bi bi-x-octagon text-danger me-1"></i> <?= esc(implode('; ', $invalidReasons)) ?>
                <?php else: ?>
                    Sem fornecedor elegível: não há custo de referência para calcular o preço.
                <?php endif ?>
            </p>
        <?php else: ?>
            <?php if ($primary === null): ?>
                <div class="alert alert-danger py-2 small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    O preço fixo de <strong><?= money($breakdown->price) ?></strong> não atinge a margem mínima com nenhum fornecedor.
                    Com <?= esc($reference->supplierName) ?> o preço mínimo é <strong><?= money($breakdown->minimumPrice) ?></strong>.
                </div>
            <?php endif ?>
            <div class="row g-4">
                <div class="col-12 col-lg-5">
                    <div class="small text-body-secondary mb-1">Preço de venda <?= $primary !== null ? '· com ' . esc($reference->supplierName) : '' ?></div>
                    <div class="selection-score mb-2"><?= money($breakdown->price) ?></div>
                    <dl class="dl-compact row mb-0 small">
                        <dt class="col-6">Lucro estimado</dt>
                        <dd class="col-6 <?= bccomp($breakdown->profit, '0', 2) < 0 ? 'text-danger' : 'text-success' ?> fw-semibold"><?= money($breakdown->profit) ?></dd>
                        <dt class="col-6">Margem</dt>
                        <dd class="col-6"><?= margin_badge($breakdown->marginPercentage, $breakdown->isBelowMinimum() ? 'below_minimum' : 'ok') ?></dd>
                        <dt class="col-6">Margem desejada</dt>
                        <dd class="col-6"><?= percent_format($breakdown->margins->desired) ?> <span class="text-body-secondary">(<?= esc($breakdown->margins->desiredSourceLabel()) ?>)</span></dd>
                        <dt class="col-6">Margem mínima</dt>
                        <dd class="col-6"><?= percent_format($breakdown->margins->minimum) ?> <span class="text-body-secondary">(<?= esc($breakdown->margins->minimumSourceLabel()) ?>)</span></dd>
                        <?php if ($breakdown->isManual()): ?>
                            <dt class="col-6">Preço sugerido</dt><dd class="col-6"><?= money($breakdown->suggestedPrice) ?></dd>
                        <?php endif ?>
                        <dt class="col-6">Preço mínimo</dt><dd class="col-6"><?= money($breakdown->minimumPrice) ?></dd>
                    </dl>
                </div>
                <div class="col-12 col-lg-7">
                    <table class="table table-sm small mb-0">
                        <tbody>
                        <tr><td>Custo do produto</td><td class="text-end"><?= money($breakdown->costPrice) ?></td></tr>
                        <tr><td>Frete do fornecedor</td><td class="text-end"><?= money($breakdown->shippingCost) ?></td></tr>
                        <tr>
                            <td>Comissão do marketplace <span class="text-body-secondary">(<?= percent_format($breakdown->config->marketplaceFeePercentage, 2) ?>)</span></td>
                            <td class="text-end"><?= money($breakdown->marketplaceFee) ?></td>
                        </tr>
                        <tr>
                            <td>Tarifa fixa do marketplace
                                <?php if (bccomp($breakdown->config->fixedFeeThreshold, '0', 2) > 0): ?>
                                    <span class="text-body-secondary">(abaixo de <?= money($breakdown->config->fixedFeeThreshold) ?>)</span>
                                <?php endif ?>
                            </td>
                            <td class="text-end"><?= money($breakdown->marketplaceFixedFee) ?></td>
                        </tr>
                        <tr>
                            <td>Impostos estimados <span class="text-body-secondary">(<?= percent_format($breakdown->config->taxPercentage, 2) ?>)</span></td>
                            <td class="text-end"><?= money($breakdown->taxAmount) ?></td>
                        </tr>
                        <tr><td>Custos extras por unidade</td><td class="text-end"><?= money($breakdown->extraFixedCost) ?></td></tr>
                        <tr>
                            <td>Custos extras sobre o preço <span class="text-body-secondary">(<?= percent_format($breakdown->config->extraCostPercentage, 2) ?>)</span></td>
                            <td class="text-end"><?= money($breakdown->extraPercentageCost) ?></td>
                        </tr>
                        <tr class="fw-semibold"><td>Total de custos</td><td class="text-end"><?= money($breakdown->totalCosts) ?></td></tr>
                        <tr class="fw-semibold"><td>Preço de venda</td><td class="text-end"><?= money($breakdown->price) ?></td></tr>
                        <tr class="fw-semibold <?= bccomp($breakdown->profit, '0', 2) < 0 ? 'text-danger' : 'text-success' ?>"><td>Lucro estimado</td><td class="text-end"><?= money($breakdown->profit) ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif ?>
    </div>
    <?php if ($context !== null): ?>
        <div class="card-footer bg-white small text-body-secondary">
            Margem calculada sobre o preço de venda.
            Arredondamento: <?= esc(mb_strtolower($context->config->roundingLabel())) ?>.
            <?php if ($product['pricing_updated_at']): ?>
                Gravado em <?= format_datetime($product['pricing_updated_at']) ?>.
            <?php endif ?>
            <a href="<?= site_url('pricing?' . http_build_query(array_filter([
                'cost_price'    => $breakdown?->costPrice,
                'shipping_cost' => $breakdown?->shippingCost,
                'extra_cost'    => $context->productExtraCost !== '0.00' ? $context->productExtraCost : null,
                'category_id'   => $product['category_id'],
                'desired_margin_percentage' => $product['desired_margin_percentage'],
                'minimum_margin_percentage' => $product['minimum_margin_percentage'],
            ], static fn ($v) => $v !== null && $v !== ''))) ?>" class="ms-1">Abrir no simulador</a>
        </div>
    <?php endif ?>
</div>

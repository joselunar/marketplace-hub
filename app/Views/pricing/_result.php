<?php
/**
 * @var App\DTO\Pricing\PriceBreakdown|null $breakdown
 * @var array<string, string>               $errors
 */
?>
<?php if ($errors !== []): ?>
    <div class="alert alert-danger small mb-0">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Não foi possível calcular</div>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $message): ?>
                <li><?= esc($message) ?></li>
            <?php endforeach ?>
        </ul>
    </div>
<?php elseif ($breakdown === null): ?>
    <div class="text-center text-body-secondary small py-4">
        <i class="bi bi-calculator display-6 d-block mb-2"></i>
        Informe ao menos o custo do produto para ver o preço.
    </div>
<?php else: ?>
    <?php $negative = bccomp($breakdown->profit, '0', 2) < 0; ?>
    <?php if ($breakdown->isManual() && $breakdown->isBelowMinimum()): ?>
        <div class="alert alert-danger py-2 small">
            <i class="bi bi-x-octagon me-1"></i>
            Este preço seria recusado: margem de <?= percent_format($breakdown->marginPercentage) ?>, abaixo da mínima de <?= percent_format($breakdown->margins->minimum) ?>.
            Preço mínimo: <strong><?= money($breakdown->minimumPrice) ?></strong>.
        </div>
    <?php endif ?>
    <div class="row g-3 mb-3 text-center">
        <div class="col-4">
            <div class="small text-body-secondary"><?= $breakdown->isManual() ? 'Preço informado' : 'Preço sugerido' ?></div>
            <div class="selection-score" data-result-price><?= money($breakdown->price) ?></div>
        </div>
        <div class="col-4">
            <div class="small text-body-secondary">Lucro estimado</div>
            <div class="selection-score <?= $negative ? 'text-danger' : 'text-success' ?>"><?= money($breakdown->profit) ?></div>
        </div>
        <div class="col-4">
            <div class="small text-body-secondary">Margem</div>
            <div class="selection-score <?= $breakdown->isBelowMinimum() ? 'text-danger' : '' ?>"><?= percent_format($breakdown->marginPercentage) ?></div>
        </div>
    </div>

    <table class="table table-sm small mb-3">
        <tbody>
        <tr><td>Custo do produto</td><td class="text-end"><?= money($breakdown->costPrice) ?></td></tr>
        <tr><td>Frete do fornecedor</td><td class="text-end"><?= money($breakdown->shippingCost) ?></td></tr>
        <tr><td>Comissão do marketplace (<?= percent_format($breakdown->config->marketplaceFeePercentage, 2) ?>)</td><td class="text-end"><?= money($breakdown->marketplaceFee) ?></td></tr>
        <tr><td>Tarifa fixa do marketplace</td><td class="text-end"><?= money($breakdown->marketplaceFixedFee) ?></td></tr>
        <tr><td>Impostos estimados (<?= percent_format($breakdown->config->taxPercentage, 2) ?>)</td><td class="text-end"><?= money($breakdown->taxAmount) ?></td></tr>
        <tr><td>Custos extras por unidade</td><td class="text-end"><?= money($breakdown->extraFixedCost) ?></td></tr>
        <tr><td>Custos extras sobre o preço (<?= percent_format($breakdown->config->extraCostPercentage, 2) ?>)</td><td class="text-end"><?= money($breakdown->extraPercentageCost) ?></td></tr>
        <tr class="fw-semibold"><td>Total de custos</td><td class="text-end"><?= money($breakdown->totalCosts) ?></td></tr>
        </tbody>
    </table>

    <dl class="dl-compact row small mb-0">
        <dt class="col-6">Margem desejada</dt>
        <dd class="col-6"><?= percent_format($breakdown->margins->desired) ?> <span class="text-body-secondary">(<?= esc($breakdown->margins->desiredSourceLabel()) ?>)</span></dd>
        <dt class="col-6">Margem mínima</dt>
        <dd class="col-6"><?= percent_format($breakdown->margins->minimum) ?> <span class="text-body-secondary">(<?= esc($breakdown->margins->minimumSourceLabel()) ?>)</span></dd>
        <?php if ($breakdown->rawPrice !== null && $breakdown->rawPrice !== $breakdown->price): ?>
            <dt class="col-6">Antes do arredondamento</dt><dd class="col-6"><?= money($breakdown->rawPrice) ?></dd>
        <?php endif ?>
        <?php if ($breakdown->isManual()): ?>
            <dt class="col-6">Preço sugerido</dt><dd class="col-6"><?= money($breakdown->suggestedPrice) ?></dd>
        <?php endif ?>
        <dt class="col-6">Preço mínimo (margem mínima)</dt><dd class="col-6"><?= money($breakdown->minimumPrice) ?></dd>
    </dl>
<?php endif ?>

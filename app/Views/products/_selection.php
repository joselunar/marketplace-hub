<?php

use App\DTO\Suppliers\RankingConfig;

/** @var App\DTO\Suppliers\SelectionResult $selection */
$primary  = $selection->primary();
$reserves = $selection->reserves();
$savedId  = $product['selected_offer_id'] !== null ? (int) $product['selected_offer_id'] : null;
$isStale  = $selection->evaluations !== [] && $savedId !== $primary?->offerId;
?>
<div class="card mb-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-trophy me-1"></i> Fornecedor selecionado</span>
        <?php if ($selection->evaluations !== []): ?>
            <form action="<?= site_url('products/' . $product['id'] . '/selection') ?>" method="post">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat me-1"></i> Recalcular</button>
            </form>
        <?php endif ?>
    </div>
    <div class="card-body">
        <?php if ($isStale): ?>
            <div class="alert alert-warning py-2 small">
                <i class="bi bi-exclamation-triangle me-1"></i>
                A seleção gravada está desatualizada em relação aos dados atuais. Clique em <strong>Recalcular</strong> para aplicar.
            </div>
        <?php endif ?>

        <?php if ($selection->evaluations === []): ?>
            <p class="text-body-secondary small mb-0">Sem ofertas vinculadas: não há o que selecionar.</p>
        <?php elseif ($primary === null): ?>
            <div class="alert alert-danger mb-3">
                <div class="fw-semibold"><i class="bi bi-x-octagon me-1"></i> Nenhum fornecedor elegível</div>
                <div class="small">O produto não deve ser publicado até que alguma oferta atenda aos critérios abaixo.</div>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <div class="col-12 col-lg-4">
                    <div class="small text-body-secondary mb-1">Principal</div>
                    <div class="h4 mb-1"><?= esc($primary->supplierName) ?></div>
                    <div class="d-flex align-items-baseline gap-2 mb-3">
                        <span class="selection-score text-success"><?= score_format($primary->score) ?></span>
                        <span class="text-body-secondary small">de 100</span>
                    </div>
                    <dl class="dl-compact row mb-0 small">
                        <dt class="col-6">Custo + frete</dt><dd class="col-6"><?= money($primary->totalCost) ?></dd>
                        <dt class="col-6">Estoque disponível</dt>
                        <dd class="col-6">
                            <?= $primary->availableStock ?>
                            <div class="text-body-secondary"><?= $primary->stockQuantity ?> informado − <?= $primary->safetyStock ?> de segurança (<?= esc($primary->safetySourceLabel()) ?>)</div>
                        </dd>
                        <dt class="col-6">Prazo de postagem</dt><dd class="col-6"><?= (int) $primary->shippingDays ?> d</dd>
                    </dl>
                    <?php if ($reserves !== []): ?>
                        <div class="small text-body-secondary mt-2 mb-1">Reservas</div>
                        <ol class="list-unstyled small mb-0">
                            <?php foreach ($reserves as $index => $reserve): ?>
                                <li><?= badge('#' . ($index + 1), 'info') ?> <?= esc($reserve->supplierName) ?> <span class="text-body-secondary">(<?= score_format($reserve->score) ?>)</span></li>
                            <?php endforeach ?>
                        </ol>
                    <?php else: ?>
                        <div class="small text-warning mt-2"><i class="bi bi-exclamation-triangle me-1"></i> Sem fornecedor reserva elegível.</div>
                    <?php endif ?>
                </div>

                <div class="col-12 col-lg-4">
                    <div class="small text-body-secondary mb-2">Motivos</div>
                    <?php foreach (RankingConfig::CRITERIA as $key => $label): ?>
                        <?php $criterion = $primary->criteria[$key]; ?>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between align-items-center small">
                                <span><?= esc($label) ?> <span class="text-body-secondary">· peso <?= number_format($criterion['weight'], 1, ',', '') ?>%</span></span>
                                <span><?= rating_badge($criterion['rating']) ?> <span class="fw-semibold ms-1"><?= score_format($criterion['score']) ?></span></span>
                            </div>
                            <div class="progress criteria-bar my-1" role="progressbar" aria-label="<?= esc($label, 'attr') ?>" aria-valuenow="<?= (int) $criterion['score'] ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar" style="width: <?= (float) $criterion['score'] ?>%"></div>
                            </div>
                            <div class="small text-body-secondary"><?= esc($criterion['note']) ?></div>
                        </div>
                    <?php endforeach ?>
                </div>

                <div class="col-12 col-lg-4">
                    <div class="small text-body-secondary mb-2">Comparação</div>
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($selection->evaluations as $evaluation): ?>
                            <li class="list-group-item px-0 d-flex justify-content-between gap-2">
                                <span>
                                    <?= esc($evaluation->supplierName) ?>
                                    <?php if (! $evaluation->isEligible()): ?>
                                        <div class="text-body-secondary">Inelegível: <?= esc(implode('; ', $evaluation->ineligibilityReasons)) ?></div>
                                    <?php endif ?>
                                </span>
                                <span class="text-nowrap">
                                    <?php if ($evaluation->isEligible()): ?>
                                        <span class="fw-semibold"><?= score_format($evaluation->score) ?></span>
                                    <?php endif ?>
                                    <?= selection_position_badge($evaluation->rank) ?>
                                </span>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </div>
            </div>
        <?php endif ?>

        <?php if ($primary === null && $selection->evaluations !== []): ?>
            <ul class="list-group list-group-flush small">
                <?php foreach ($selection->evaluations as $evaluation): ?>
                    <li class="list-group-item px-0">
                        <span class="fw-semibold"><?= esc($evaluation->supplierName) ?>:</span>
                        <?= esc(implode('; ', $evaluation->ineligibilityReasons)) ?>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>
    <?php if ($selection->evaluations !== []): ?>
        <div class="card-footer bg-white small text-body-secondary">
            Pesos:
            <?php foreach (RankingConfig::CRITERIA as $key => $label): ?>
                <?= esc(mb_strtolower($label)) ?> <?= number_format($selection->config->weightShare($key), 1, ',', '') ?>%<?= $key !== array_key_last(RankingConfig::CRITERIA) ? ' ·' : '' ?>
            <?php endforeach ?>
            — estoque de segurança global <?= $selection->config->globalSafetyStock ?> un., prazo máximo <?= $selection->config->maxShippingDays ?> dias.
            <?php if ($product['selection_updated_at']): ?>
                Gravado em <?= format_datetime($product['selection_updated_at']) ?>.
            <?php endif ?>
        </div>
    <?php endif ?>
</div>

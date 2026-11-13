<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$product = $data['product'] ?? [];
$unit = $sim['unit'] ?? [];
$mei = $sim['mei'] ?? [];
$assumptions = $sim['assumptions'] ?? [];
$fixed = $sim['fixed_costs'] ?? [];
$breakEven = $sim['break_even'] ?? [];
$pid = (int) ($product['id'] ?? 0);
$meiClass = match ($mei['level'] ?? 'ok') {
    'exceeded' => 'danger',
    'warning'  => 'warning',
    default    => 'success',
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h2 class="h4 mb-1">Operação mensal · <?= esc($product['name'] ?? '') ?></h2>
        <div class="text-body-secondary small"><?= esc($product['sku'] ?? '') ?></div>
    </div>
    <a class="btn btn-sm btn-light" href="<?= site_url('profitability/commercial/' . $pid) ?>">Voltar ao comercial</a>
</div>

<?php if (! empty($cepPending)): ?>
    <div class="alert alert-warning">
        Frete desta simulação ainda é estimativo; CEP de expedição não confirmado. Resultado operacional não é lucro comprovado.
    </div>
<?php endif ?>

<?php if (empty($fixed['das_configured'])): ?>
    <div class="alert alert-warning">
        <strong>DAS-MEI:</strong> está zerado nas configurações. Informe o valor mensal em Configurações → Custos fixos para o ponto de equilíbrio refletir essa despesa.
    </div>
<?php endif ?>

<div class="alert alert-<?= $meiClass ?>">
    <strong>MEI:</strong> <?= esc($mei['message'] ?? '') ?>
    <div class="small mt-1">
        Teto configurado <?= money($mei['annual_limit'] ?? null) ?>
        · alerta em <?= money($mei['alert_threshold'] ?? null) ?>
        · projeção do maior cenário <?= money($mei['sample_annualized'] ?? null) ?>
        (<?= percent_format($mei['utilization_pct'] ?? 0, 1) ?> do teto).
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="post" action="<?= site_url('profitability/commercial/' . $pid . '/ops') ?>" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-3">
                <label class="form-label" for="sale_price">Preço de anúncio (R$)</label>
                <input type="text" inputmode="decimal" class="form-control form-control-sm" id="sale_price" name="sale_price"
                       value="<?= esc($unit['sale_price'] ?? '', 'attr') ?>">
                <div class="form-text">Padrão: preço-alvo 20% quando disponível.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="volumes">Volumes (separados por vírgula)</label>
                <input type="text" class="form-control form-control-sm" id="volumes" name="volumes"
                       value="<?= esc(implode(',', $volumes ?? [10, 20, 30, 50, 100]), 'attr') ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">Simular</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-body-secondary">Contribuição unitária</div>
            <div class="fs-4"><?= money($unit['contribution'] ?? null) ?></div>
            <div class="small">preço <?= money($unit['sale_price'] ?? null) ?> − variáveis <?= money($unit['variable_cost'] ?? null) ?></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-body-secondary">PE — operação</div>
            <div class="fs-4">
                <?= isset($breakEven['operation']) ? ((int) $breakEven['operation'] . ' vendas') : '—' ?>
            </div>
            <div class="small">fixos operação <?= money($fixed['operation_total'] ?? $assumptions['fixed_operation'] ?? null) ?> (sem VPS compartilhada)</div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-body-secondary">PE — empresa</div>
            <div class="fs-4">
                <?= isset($breakEven['company']) ? ((int) $breakEven['company'] . ' vendas') : (isset($sim['break_even_units']) ? ((int) $sim['break_even_units'] . ' vendas') : '—') ?>
            </div>
            <div class="small">todos os fixos <?= money($fixed['company_total'] ?? $assumptions['fixed_monthly'] ?? null) ?></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-body-secondary">Capital / unidade</div>
            <div class="fs-4"><?= money($unit['working_capital_unit'] ?? null) ?></div>
            <div class="small">estoque fornecedor: <?= esc((string) ($sim['stock'] ?? '—')) ?></div>
        </div></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold">Custos fixos (sem duplicar linhas)</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Item</th><th>Tipo</th><th class="text-end">Valor</th></tr></thead>
            <tbody>
            <?php foreach ($fixed['lines'] ?? [] as $line): ?>
                <tr>
                    <td><?= esc($line['label'] ?? '') ?></td>
                    <td class="small"><?= ($line['bucket'] ?? '') === 'shared' ? 'compartilhado' : 'operação' ?></td>
                    <td class="text-end"><?= money($line['amount'] ?? null) ?></td>
                </tr>
            <?php endforeach ?>
            <tr class="fw-semibold">
                <td colspan="2">Total operação</td>
                <td class="text-end"><?= money($fixed['operation_total'] ?? null) ?></td>
            </tr>
            <tr class="fw-semibold">
                <td colspan="2">Total compartilhado</td>
                <td class="text-end"><?= money($fixed['shared_total'] ?? null) ?></td>
            </tr>
            <tr class="fw-semibold table-light">
                <td colspan="2">Total empresa</td>
                <td class="text-end"><?= money($fixed['company_total'] ?? null) ?></td>
            </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold">Cenários de volume</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
            <tr>
                <th>Vendas/mês</th>
                <th>Faturamento bruto</th>
                <th>Custos variáveis</th>
                <th>Margem de contribuição</th>
                <th>Resultado (operação)</th>
                <th>Resultado (empresa)</th>
                <th>Capital de giro</th>
                <th>Anualizado</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sim['scenarios'] ?? [] as $row): ?>
                <tr class="<?= ! empty($row['covers_fixed']) ? '' : 'table-warning' ?>">
                    <td class="fw-semibold"><?= (int) ($row['units'] ?? 0) ?></td>
                    <td><?= money($row['gross_revenue'] ?? null) ?></td>
                    <td><?= money($row['variable_costs'] ?? null) ?></td>
                    <td><?= money($row['contribution_margin'] ?? null) ?></td>
                    <td><?= money($row['operating_result_operation'] ?? null) ?></td>
                    <td class="fw-semibold"><?= money($row['operating_result'] ?? null) ?></td>
                    <td><?= money($row['working_capital'] ?? null) ?></td>
                    <td><?= money($row['annualized_revenue'] ?? null) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<p class="small text-body-secondary mb-0">
    <?= esc($sim['reliability_note'] ?? '') ?>
    <?= esc($assumptions['tax_note'] ?? '') ?>
    Tributação não validada pelo contador não é presumida nesta simulação.
</p>
<?= $this->endSection() ?>

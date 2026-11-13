<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$product = $data['product'] ?? [];
$unit = $sim['unit'] ?? [];
$mei = $sim['mei'] ?? [];
$assumptions = $sim['assumptions'] ?? [];
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
        Frete e capital de giro desta simulação herdam a última análise; CEP real Dropify/SP permanece pendente.
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
            <div class="small text-body-secondary">Ponto de equilíbrio</div>
            <div class="fs-4">
                <?= isset($sim['break_even_units']) ? ((int) $sim['break_even_units'] . ' vendas') : '—' ?>
            </div>
            <div class="small">fixos mensais <?= money($assumptions['fixed_monthly'] ?? null) ?></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-body-secondary">Capital / unidade</div>
            <div class="fs-4"><?= money($unit['working_capital_unit'] ?? null) ?></div>
            <div class="small">custo de compra (giro antecipado)</div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-body-secondary">Estoque fornecedor</div>
            <div class="fs-4"><?= esc((string) ($sim['stock'] ?? '—')) ?></div>
            <div class="small">não é demanda verificada</div>
        </div></div>
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
                <th>Custos fixos</th>
                <th>Resultado operacional</th>
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
                    <td><?= money($row['fixed_costs'] ?? null) ?></td>
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

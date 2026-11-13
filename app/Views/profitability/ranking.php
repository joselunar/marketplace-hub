<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$badge = static function (string $classification): string {
    return match ($classification) {
        'rentavel'     => 'success',
        'margem_baixa' => 'warning',
        'prejuizo'     => 'danger',
        default        => 'secondary',
    };
};
$relBadge = static function (?string $rel): string {
    return match ($rel) {
        'confirmado' => 'success',
        'estimado'   => 'warning',
        default      => 'secondary',
    };
};
?>

<p class="text-body-secondary mb-3">
    Ordenado pela <strong>margem indicativa</strong> (usa tarifas confirmadas e frete estimado quando disponível).
    Produtos com custos essenciais só estimados/desconhecidos <strong>não</strong> são classificados como rentáveis nem liberados para publicação.
</p>

<div class="card filters-card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="q">Busca</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>">
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label" for="supplier_id">Fornecedor</label>
                <select class="form-select form-select-sm" id="supplier_id" name="supplier_id">
                    <option value="">Todos</option>
                    <?php foreach ($suppliers as $id => $name): ?>
                        <option value="<?= (int) $id ?>" <?= ((string) ($filters['supplier_id'] ?? '') === (string) $id) ? 'selected' : '' ?>><?= esc($name) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label" for="classification">Classificação</label>
                <select class="form-select form-select-sm" id="classification" name="classification">
                    <option value="">Todas</option>
                    <?php foreach ($labels as $value => $label): ?>
                        <option value="<?= esc($value, 'attr') ?>" <?= (($filters['classification'] ?? '') === $value) ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label" for="min_indicative">Indicativa mín. %</label>
                <input type="text" inputmode="decimal" class="form-control form-control-sm" id="min_indicative" name="min_indicative" value="<?= esc($filters['min_indicative'] ?? '', 'attr') ?>">
            </div>
            <div class="col-sm-6 col-lg-2">
                <div class="form-check mt-4">
                    <input type="hidden" name="only_with_fee" value="0">
                    <input class="form-check-input" type="checkbox" value="1" id="only_with_fee" name="only_with_fee" <?= (($filters['only_with_fee'] ?? '') === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="only_with_fee">Só com tarifa ML</label>
                </div>
            </div>
            <div class="col-sm-6 col-lg-1">
                <button type="submit" class="btn btn-sm btn-primary w-100">Filtrar</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
            <tr>
                <th>#</th>
                <th>Produto</th>
                <th>Custo</th>
                <th>Preço</th>
                <th>Tarifa ML</th>
                <th>Frete</th>
                <th>Indicativa</th>
                <th>Oficial</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="10" class="text-body-secondary">Nenhum produto no ranking com os filtros atuais.</td></tr>
            <?php endif ?>
            <?php foreach ($rows as $i => $row): ?>
                <tr>
                    <td class="text-body-secondary"><?= $i + 1 ?></td>
                    <td>
                        <div class="fw-semibold"><?= esc($row['product_name'] ?? '') ?></div>
                        <div class="small text-body-secondary"><?= esc($row['sku'] ?? '') ?> · <?= esc($row['supplier_name'] ?? '') ?> · origem <?= esc($row['origin_uf'] ?? '—') ?></div>
                        <div class="small text-body-secondary"><?= esc($row['ranking_justification'] ?? '') ?></div>
                    </td>
                    <td><?= money($row['cost_price'] ?? null) ?></td>
                    <td><?= money($row['sale_price'] ?? null) ?></td>
                    <td>
                        <?= $row['marketplace_fee_total'] !== null ? money($row['marketplace_fee_total']) : '—' ?>
                        <div><span class="badge text-bg-<?= $relBadge($row['marketplace_fee_source'] ?? null) ?>"><?= esc($row['marketplace_fee_source'] ?? '—') ?></span></div>
                    </td>
                    <td>
                        <?= $row['seller_shipping_cost'] !== null ? money($row['seller_shipping_cost']) : '—' ?>
                        <div><span class="badge text-bg-<?= $relBadge($row['seller_shipping_source'] ?? null) ?>"><?= esc($row['seller_shipping_source'] ?? '—') ?></span></div>
                    </td>
                    <td class="fw-semibold"><?= percent_format($row['indicative_margin_pct'] ?? 0, 2) ?><div class="small"><?= money($row['indicative_margin'] ?? null) ?></div></td>
                    <td><?= percent_format($row['contribution_margin_pct'] ?? 0, 2) ?></td>
                    <td><span class="badge text-bg-<?= $badge((string) ($row['classification'] ?? '')) ?>"><?= esc($labels[$row['classification']] ?? $row['classification']) ?></span></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="<?= site_url('profitability/' . $row['product_id']) ?>">Detalhe</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>

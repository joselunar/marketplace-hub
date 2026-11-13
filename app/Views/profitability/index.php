<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$badge = static function (string $classification): string {
    return match ($classification) {
        'rentavel'         => 'success',
        'margem_baixa'     => 'warning',
        'prejuizo'         => 'danger',
        default            => 'secondary',
    };
};
?>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['total', 'Importados analisados', 'primary'],
        ['rentavel', 'Rentáveis', 'success'],
        ['margem_baixa', 'Margem baixa', 'warning'],
        ['prejuizo', 'Prejuízo', 'danger'],
        ['aguardando_dados', 'Aguardando dados', 'secondary'],
    ] as [$key, $label, $color]): ?>
        <div class="col-6 col-md">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body py-3">
                    <div class="text-body-secondary small"><?= esc($label) ?></div>
                    <div class="fs-3 fw-semibold text-<?= $color ?>"><?= (int) ($counts[$key] ?? 0) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach ?>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="<?= site_url('imports/dropify') ?>" class="btn btn-sm btn-success"><i class="bi bi-cloud-upload me-1"></i>Importar Dropify</a>
    <a href="<?= site_url('profitability/checklist') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-clipboard-check me-1"></i>Checklist Dropify</a>
</div>

<div class="card filters-card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="q">Busca</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>" placeholder="SKU, nome…">
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label" for="supplier_id">Fornecedor</label>
                <select class="form-select form-select-sm" id="supplier_id" name="supplier_id">
                    <option value="">Todos</option>
                    <?php foreach ($suppliers as $id => $name): ?>
                        <option value="<?= $id ?>" <?= ($filters['supplier_id'] ?? '') === (string) $id ? 'selected' : '' ?>><?= esc($name) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label" for="classification">Rentabilidade</label>
                <select class="form-select form-select-sm" id="classification" name="classification">
                    <option value="">Todas</option>
                    <?php foreach ($labels as $k => $v): ?>
                        <option value="<?= esc($k, 'attr') ?>" <?= ($filters['classification'] ?? '') === $k ? 'selected' : '' ?>><?= esc($v) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label" for="min_price">Preço mín.</label>
                <input type="text" inputmode="decimal" class="form-control form-control-sm" id="min_price" name="min_price" value="<?= esc($filters['min_price'] ?? '', 'attr') ?>">
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label" for="max_price">Preço máx.</label>
                <input type="text" inputmode="decimal" class="form-control form-control-sm" id="max_price" name="max_price" value="<?= esc($filters['max_price'] ?? '', 'attr') ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
                <a class="btn btn-sm btn-light" href="<?= site_url('profitability') ?>">Limpar</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Produtos analisados</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                    <tr>
                        <th>Produto</th>
                        <th>Custo</th>
                        <th>Preço</th>
                        <th>Clássico</th>
                        <th>Premium</th>
                        <th>Margem</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="8" class="text-center text-body-secondary py-4">Nenhuma análise ainda. Importe o CSV Dropify para começar.</td></tr>
                    <?php endif ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= esc($row['product_name'] ?? '') ?></div>
                                <div class="small text-body-secondary"><?= esc($row['sku'] ?? '') ?> · <?= esc($row['supplier_name'] ?? '—') ?></div>
                            </td>
                            <td><?= money($row['cost_price'] ?? null) ?></td>
                            <td><?= money($row['sale_price'] ?? null) ?></td>
                            <td class="small"><?= $row['classic_fee_total'] !== null ? money($row['classic_fee_total']) : '<span class="text-body-secondary">—</span>' ?></td>
                            <td class="small"><?= $row['premium_fee_total'] !== null ? money($row['premium_fee_total']) : '<span class="text-body-secondary">—</span>' ?></td>
                            <td>
                                <?php if ($row['contribution_margin_pct'] !== null): ?>
                                    <?= percent_format($row['contribution_margin_pct'], 2) ?>
                                    <div class="small text-body-secondary"><?= money($row['contribution_margin'] ?? null) ?></div>
                                <?php else: ?>—<?php endif ?>
                            </td>
                            <td><span class="badge text-bg-<?= $badge((string) $row['classification']) ?>"><?= esc($labels[$row['classification']] ?? $row['classification']) ?></span></td>
                            <td class="text-end"><a class="btn btn-sm btn-light" href="<?= site_url('profitability/' . $row['product_id']) ?>">Detalhe</a></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pager): ?>
                <div class="card-footer bg-white"><?= $pager->links() ?></div>
            <?php endif ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Maiores margens</div>
            <ul class="list-group list-group-flush">
                <?php if ($top === []): ?>
                    <li class="list-group-item text-body-secondary">Nenhum produto classificado como rentável ainda.</li>
                <?php endif ?>
                <?php foreach ($top as $item): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div>
                            <a href="<?= site_url('profitability/' . $item['product_id']) ?>" class="fw-semibold text-decoration-none"><?= esc($item['product_name']) ?></a>
                            <div class="small text-body-secondary"><?= esc($item['sku']) ?></div>
                        </div>
                        <span class="badge text-bg-success"><?= percent_format($item['contribution_margin_pct'], 2) ?></span>
                    </li>
                <?php endforeach ?>
            </ul>
        </div>
        <div class="alert alert-light border mt-3 small mb-0">
            Tarifas Clássico/Premium vêm de <code>listing_prices</code> quando a conta ML e a categoria estão disponíveis.
            Frete do vendedor só entra como confirmado se a API devolver custo interpretável; caso contrário o produto fica em <strong>Aguardando dados</strong>.
        </div>
    </div>
</div>
<?= $this->endSection() ?>

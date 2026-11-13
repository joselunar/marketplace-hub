<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$classification = (string) ($analysis['classification'] ?? 'aguardando_dados');
$badge = match ($classification) {
    'rentavel' => 'success',
    'margem_baixa' => 'warning',
    'prejuizo' => 'danger',
    default => 'secondary',
};
$lines = $breakdown['lines'] ?? [];
$quotes = $breakdown['quotes'] ?? [];
$reasons = json_decode((string) ($analysis['block_reasons'] ?? '[]'), true) ?: ($breakdown['block_reasons'] ?? []);
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h2 class="h4 mb-1"><?= esc($analysis['product_name'] ?? '') ?></h2>
        <div class="text-body-secondary small">
            <?= esc($analysis['sku'] ?? '') ?>
            · <?= esc($analysis['supplier_name'] ?? 'Sem fornecedor') ?>
            · confiabilidade: <strong><?= esc($analysis['cost_reliability'] ?? '—') ?></strong>
            · origem: <?= esc($analysis['calculation_source'] ?? '—') ?>
            <?php if (! empty($analysis['quoted_at'])): ?> · <?= esc($analysis['quoted_at']) ?><?php endif ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <span class="badge text-bg-<?= $badge ?> align-self-center fs-6"><?= esc($labels[$classification] ?? $classification) ?></span>
        <form method="post" action="<?= site_url('profitability/' . $analysis['product_id'] . '/recalculate') ?>">
            <?= csrf_field() ?>
            <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Recalcular</button>
        </form>
        <a class="btn btn-sm btn-light" href="<?= site_url('products/' . $analysis['product_id']) ?>">Produto</a>
    </div>
</div>

<?php if ($reasons !== []): ?>
    <div class="alert alert-warning">
        <div class="fw-semibold mb-1">Motivos de bloqueio / atenção</div>
        <ul class="mb-0">
            <?php foreach ($reasons as $reason): ?>
                <li><?= esc(is_string($reason) ? $reason : json_encode($reason)) ?></li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold">Categoria Mercado Livre</div>
    <div class="card-body">
        <?php
        $catId = $product['marketplace_category_id'] ?? ($analysis['marketplace_category_id'] ?? null);
        $catName = $product['marketplace_category_name'] ?? null;
        $catSource = $product['marketplace_category_source'] ?? null;
        $sourceCategory = $offer['source_category'] ?? null;
        ?>
        <div class="small text-body-secondary mb-2">
            Fornecedor: <?= esc($sourceCategory ?: '—') ?>
            · MLB atual: <?= $catId ? '<code>' . esc($catId) . '</code> ' . esc((string) $catName) : 'não confirmada' ?>
            <?php if ($catSource): ?> · origem <code><?= esc($catSource) ?></code><?php endif ?>
        </div>
        <?php if (! empty($suggestions)): ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Sugestão (API)</th><th>Domínio</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($suggestions as $suggestion): ?>
                        <tr>
                            <td>
                                <code><?= esc($suggestion['category_id'] ?? '') ?></code>
                                <?= esc($suggestion['category_name'] ?? '') ?>
                            </td>
                            <td class="small text-body-secondary"><?= esc($suggestion['domain_name'] ?? $suggestion['domain_id'] ?? '') ?></td>
                            <td class="text-end">
                                <form method="post" action="<?= site_url('profitability/' . $analysis['product_id'] . '/category') ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="category_id" value="<?= esc($suggestion['category_id'] ?? '', 'attr') ?>">
                                    <input type="hidden" name="remember_mapping" value="1">
                                    <input type="hidden" name="origin" value="confirmed">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Confirmar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <div class="form-text mb-3">Sugestões via <code>domain_discovery/search</code>. Nada é aplicado automaticamente.</div>
        <?php else: ?>
            <p class="small text-body-secondary">Sem sugestões no momento (conta ausente, título curto ou preditor sem retorno).</p>
        <?php endif ?>
        <form method="post" action="<?= site_url('profitability/' . $analysis['product_id'] . '/category') ?>" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-5">
                <label class="form-label" for="category_id_manual">ID MLB manual</label>
                <input type="text" class="form-control form-control-sm" id="category_id_manual" name="category_id" placeholder="MLB123456" required pattern="[A-Za-z]{3}[0-9]+">
            </div>
            <div class="col-md-4">
                <div class="form-check mt-4">
                    <input type="hidden" name="remember_mapping" value="0">
                    <input class="form-check-input" type="checkbox" value="1" id="remember_mapping" name="remember_mapping" checked>
                    <label class="form-check-label small" for="remember_mapping">Reutilizar na categoria do fornecedor</label>
                </div>
                <input type="hidden" name="origin" value="manual">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-sm btn-primary w-100">Validar e confirmar</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">Composição do resultado</div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th>Item</th><th>Valor</th><th>Confiabilidade</th><th>Nota</th></tr></thead>
                    <tbody>
                    <tr>
                        <td>Preço de venda</td>
                        <td><?= money($analysis['sale_price'] ?? null) ?></td>
                        <td><span class="badge text-bg-success">confirmado</span></td>
                        <td class="small text-body-secondary">Preço sugerido/importado ou simulado</td>
                    </tr>
                    <?php foreach ($lines as $line): ?>
                        <tr>
                            <td><?= esc($line['label'] ?? $line['code'] ?? '') ?></td>
                            <td><?= isset($line['amount']) && $line['amount'] !== null ? money($line['amount']) : '—' ?></td>
                            <td><span class="badge text-bg-<?= ($line['reliability'] ?? '') === 'confirmado' ? 'success' : (($line['reliability'] ?? '') === 'estimado' ? 'warning' : 'secondary') ?>"><?= esc($line['reliability'] ?? '') ?></span></td>
                            <td class="small text-body-secondary"><?= esc($line['reason'] ?? '') ?></td>
                        </tr>
                    <?php endforeach ?>
                    <tr class="table-light">
                        <td class="fw-semibold">Margem de contribuição</td>
                        <td class="fw-semibold"><?= money($analysis['contribution_margin'] ?? null) ?> (<?= percent_format($analysis['contribution_margin_pct'] ?? 0, 2) ?>)</td>
                        <td colspan="2"></td>
                    </tr>
                    <tr>
                        <td>Resultado após rateio de fixos</td>
                        <td><?= money($analysis['operating_result'] ?? null) ?></td>
                        <td><span class="badge text-bg-warning">estimado</span></td>
                        <td class="small text-body-secondary">Fixos mensais <?= money($breakdown['monthly_fixed_costs'] ?? null) ?> ÷ unidades assumidas; VPS pode ser compartilhada.</td>
                    </tr>
                    <tr>
                        <td>Preço mínimo (aprox.)</td>
                        <td><?= money($analysis['minimum_viable_price'] ?? null) ?></td>
                        <td><span class="badge text-bg-warning">estimado</span></td>
                        <td class="small text-body-secondary">Recálculo iterativo completo com nova cotação ML fica para refinamentos; aqui usa decomposição da tarifa atual.</td>
                    </tr>
                    <tr>
                        <td>Ponto de equilíbrio</td>
                        <td><?= $analysis['break_even_units'] !== null ? (int) $analysis['break_even_units'] . ' un./mês' : '—' ?></td>
                        <td><span class="badge text-bg-warning">estimado</span></td>
                        <td class="small text-body-secondary">Demanda não verificada.</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">Clássico × Premium</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-semibold">Clássico (gold_special)</div>
                            <div>Tarifa total: <?= $analysis['classic_fee_total'] !== null ? money($analysis['classic_fee_total']) : '<span class="text-body-secondary">indisponível</span>' ?></div>
                            <div>Margem: <?= $analysis['classic_margin_pct'] !== null ? percent_format($analysis['classic_margin_pct'], 2) : '—' ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-semibold">Premium (gold_pro)</div>
                            <div>Tarifa total: <?= $analysis['premium_fee_total'] !== null ? money($analysis['premium_fee_total']) : '<span class="text-body-secondary">indisponível</span>' ?></div>
                            <div>Margem: <?= $analysis['premium_margin_pct'] !== null ? percent_format($analysis['premium_margin_pct'], 2) : '—' ?></div>
                        </div>
                    </div>
                </div>
                <?php if (! empty($quotes['shipping']['reason'])): ?>
                    <div class="small text-body-secondary mt-3">Frete: <?= esc($quotes['shipping']['reliability'] ?? '') ?> — <?= esc($quotes['shipping']['reason']) ?></div>
                <?php endif ?>
                <?php if (! empty($quotes['shipping']['origin']['note'])): ?>
                    <div class="small text-body-secondary mt-1">Origem física: <?= esc($quotes['shipping']['origin']['note']) ?></div>
                <?php endif ?>
                <?php if (! empty($quotes['logistics']['reason'])): ?>
                    <div class="small text-body-secondary mt-1">
                        Logística da conta: <code><?= esc(($quotes['logistics']['shipping_mode'] ?? '—') . '/' . ($quotes['logistics']['logistic_type'] ?? '—')) ?></code>
                        · <?= esc($quotes['logistics']['source'] ?? '') ?>
                        — <?= esc($quotes['logistics']['reason']) ?>
                        · <a href="<?= site_url('profitability/logistics') ?>">ajustar</a>
                    </div>
                <?php endif ?>
                <?php if (! empty($quotes['quoted_at'])): ?>
                    <div class="small text-body-secondary mt-1">Consulta de tarifas: <?= esc($quotes['quoted_at']) ?></div>
                <?php endif ?>
                <?php if (isset($breakdown['indicative_margin_pct'])): ?>
                    <div class="small mt-2">
                        Margem indicativa (estimados incluídos):
                        <strong><?= percent_format($breakdown['indicative_margin_pct'], 2) ?></strong>
                        (<?= money($breakdown['indicative_margin'] ?? null) ?>)
                        — não libera publicação.
                    </div>
                <?php endif ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">Simular preço</div>
            <div class="card-body">
                <form method="post" action="<?= site_url('profitability/' . $analysis['product_id'] . '/simulate') ?>" class="row g-2">
                    <?= csrf_field() ?>
                    <div class="col-8">
                        <label class="form-label" for="sale_price">Preço de venda (R$)</label>
                        <input type="text" inputmode="decimal" class="form-control" id="sale_price" name="sale_price" value="<?= esc((string) ($analysis['sale_price'] ?? ''), 'attr') ?>" required>
                    </div>
                    <div class="col-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Aplicar</button>
                    </div>
                </form>
                <div class="form-text mt-2">Recota tarifas ML para o novo preço quando a conta/categoria permitirem. Nenhum anúncio é publicado.</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white fw-semibold">Histórico</div>
            <ul class="list-group list-group-flush">
                <?php if ($history === []): ?>
                    <li class="list-group-item text-body-secondary">Sem histórico.</li>
                <?php endif ?>
                <?php foreach ($history as $item): ?>
                    <li class="list-group-item small">
                        <div class="d-flex justify-content-between">
                            <span><?= esc($item['created_at'] ?? '') ?></span>
                            <span class="badge text-bg-light"><?= esc($labels[$item['classification']] ?? $item['classification']) ?></span>
                        </div>
                        <div><?= money($item['sale_price'] ?? null) ?> · margem <?= percent_format($item['contribution_margin_pct'] ?? 0, 2) ?></div>
                        <div class="text-body-secondary"><?= esc($item['calculation_source'] ?? '') ?> · <?= esc($item['cost_reliability'] ?? '') ?></div>
                    </li>
                <?php endforeach ?>
            </ul>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

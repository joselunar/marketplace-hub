<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$product = $data['product'] ?? [];
$offer = $data['offer'] ?? [];
$analysis = $data['analysis'] ?? [];
$targets = $data['targets'] ?? [];
$competition = $data['competition'] ?? [];
$score = $data['score'] ?? [];
$observations = $data['observations'] ?? [];
$stats = $competition['stats'] ?? [];
$pid = (int) ($product['id'] ?? 0);
$relBadge = static function (?string $rel): string {
    return match ($rel) {
        'confirmado' => 'success',
        'estimado', 'nao_garantido' => 'warning',
        default => 'secondary',
    };
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h2 class="h4 mb-1"><?= esc($product['name'] ?? '') ?></h2>
        <div class="text-body-secondary small">
            <?= esc($product['sku'] ?? '') ?>
            · custo <?= money($offer['cost_price'] ?? $analysis['cost_price'] ?? null) ?>
            · estoque <?= esc((string) ($offer['stock_quantity'] ?? '—')) ?>
            · UF <?= esc($offer['origin_uf'] ?? $product['origin_uf'] ?? '—') ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <form method="post" action="<?= site_url('profitability/commercial/' . $pid . '/refresh') ?>">
            <?= csrf_field() ?>
            <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-arrow-repeat me-1"></i><?= empty($data['intelligence']) ? 'Cotar agora' : 'Recotar' ?></button>
        </form>
        <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('profitability/commercial/' . $pid . '/ops') ?>">Operação mensal</a>
        <a class="btn btn-sm btn-light" href="<?= site_url('profitability/' . $pid) ?>">Rentabilidade</a>
    </div>
</div>

<?php if (empty($data['intelligence'])): ?>
    <div class="alert alert-info">
        Ainda não há snapshot comercial deste produto. Use <strong>Cotar agora</strong> para calcular preços-alvo (com reconsulta de tarifas) e buscar concorrentes no Mercado Livre.
    </div>
<?php endif ?>

<?php if (! empty($cepPending)): ?>
    <div class="alert alert-warning">
        CEP de expedição do fornecedor pendente. Frete cotado com endereço da conta VITRINEHUB não deve ser tratado como custo real da Dropify/SP.
    </div>
<?php endif ?>

<?php if ($score !== []): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-body-secondary small">Score comercial</div>
                    <div class="display-6"><?= (int) ($score['total'] ?? 0) ?></div>
                    <div class="small"><?= ! empty($score['proven_profit']) ? 'Lucro comprovado (parcial)' : 'Potencial comercial' ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-9">
            <div class="card h-100">
                <div class="card-body">
                    <div class="fw-semibold mb-1">Recomendação</div>
                    <p class="mb-2"><?= esc($score['recommendation'] ?? '') ?></p>
                    <div class="small text-body-secondary">
                        Confiabilidade dos dados:
                        <span class="badge text-bg-<?= $relBadge($score['data_reliability'] ?? null) ?>"><?= esc($score['data_reliability'] ?? '—') ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif ?>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold">Preços-alvo por margem (com re-cotação ML)</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
            <tr>
                <th>Meta</th>
                <th>Preço simulado</th>
                <th>Tarifa ML</th>
                <th>Frete vendedor</th>
                <th>Indicativa</th>
                <th>Garantia</th>
                <th>Nota</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($targets === []): ?>
                <tr><td colspan="7" class="text-body-secondary">Sem simulação. Use Recotar.</td></tr>
            <?php endif ?>
            <?php foreach ($targets as $t): ?>
                <tr>
                    <td><?= percent_format($t['target_margin_pct'] ?? 0, 0) ?></td>
                    <td class="fw-semibold"><?= money($t['sale_price'] ?? null) ?></td>
                    <td>
                        <?= money($t['marketplace_fee_total'] ?? null) ?>
                        <div><span class="badge text-bg-<?= $relBadge($t['marketplace_fee_reliability'] ?? null) ?>"><?= esc($t['marketplace_fee_reliability'] ?? '—') ?></span></div>
                    </td>
                    <td>
                        <?= money($t['seller_shipping_cost'] ?? null) ?>
                        <div><span class="badge text-bg-<?= $relBadge($t['seller_shipping_reliability'] ?? null) ?>"><?= esc($t['seller_shipping_reliability'] ?? '—') ?></span></div>
                    </td>
                    <td><?= percent_format($t['indicative_margin_pct'] ?? 0, 2) ?></td>
                    <td><span class="badge text-bg-<?= $relBadge($t['guarantee'] ?? null) ?>"><?= esc($t['guarantee'] ?? '—') ?></span></td>
                    <td class="small"><?= esc($t['note'] ?? '') ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold">Concorrência Mercado Livre</div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-sm-3"><div class="small text-body-secondary">Método</div><div><?= esc($competition['method'] ?? '—') ?></div></div>
            <div class="col-sm-3"><div class="small text-body-secondary">Mínimo</div><div><?= money($stats['min'] ?? null) ?></div></div>
            <div class="col-sm-3"><div class="small text-body-secondary">Mediana</div><div class="fw-semibold"><?= money($stats['median'] ?? null) ?></div></div>
            <div class="col-sm-3"><div class="small text-body-secondary">Máximo</div><div><?= money($stats['max'] ?? null) ?></div></div>
        </div>
        <?php if (! empty($competition['query'])): ?>
            <div class="small text-body-secondary mb-2">Consulta: <code><?= esc($competition['query']) ?></code>
                <?php if (! empty($competition['ean'])): ?> · EAN/GTIN <code><?= esc($competition['ean']) ?></code><?php endif ?>
                · <?= (int) ($stats['count'] ?? 0) ?> anúncio(s)
            </div>
        <?php endif ?>
        <?php if (! empty($competition['limitations'])): ?>
            <ul class="small mb-3">
                <?php foreach ($competition['limitations'] as $lim): ?>
                    <li><?= esc($lim) ?></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>

        <div class="table-responsive mb-3">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th>Anúncio</th>
                    <th>Preço</th>
                    <th>Frete grátis</th>
                    <th>Equivalência</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (array_slice($competition['items'] ?? [], 0, 12) as $item): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold small"><?= esc($item['title'] ?? '') ?></div>
                            <div class="text-body-secondary small"><?= esc($item['seller_nickname'] ?? '') ?> · <?= esc($item['id'] ?? '') ?></div>
                        </td>
                        <td><?= money($item['price'] ?? null) ?></td>
                        <td><?= ! empty($item['free_shipping']) ? 'sim' : 'não' ?></td>
                        <td class="small"><?= esc($item['equivalence_note'] ?? '') ?></td>
                        <td>
                            <?php if (! empty($item['permalink'])): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= esc($item['permalink'], 'attr') ?>" target="_blank" rel="noopener">Abrir</a>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if (($competition['items'] ?? []) === []): ?>
                    <tr><td colspan="5" class="text-body-secondary">Nenhum anúncio comparável via API. Registre manualmente abaixo.</td></tr>
                <?php endif ?>
                </tbody>
            </table>
        </div>

        <div class="fw-semibold mb-2">Registro manual (quando a API não cobrir)</div>
        <form method="post" action="<?= site_url('profitability/commercial/' . $pid . '/competitor') ?>" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-4">
                <label class="form-label" for="title">Título / descrição</label>
                <input type="text" class="form-control form-control-sm" id="title" name="title" required maxlength="255">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="price">Preço (R$)</label>
                <input type="text" inputmode="decimal" class="form-control form-control-sm" id="price" name="price" required>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="permalink">URL da fonte</label>
                <input type="url" class="form-control form-control-sm" id="permalink" name="permalink" placeholder="https://...">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="note">Nota de equivalência</label>
                <input type="text" class="form-control form-control-sm" id="note" name="note" maxlength="255">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-sm btn-outline-primary w-100">Salvar</button>
            </div>
        </form>
    </div>
</div>

<?php if ($observations !== []): ?>
    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">Observações recentes</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Quando</th><th>Fonte</th><th>Título</th><th>Preço</th><th>Nota</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($observations, 0, 15) as $obs): ?>
                    <tr>
                        <td class="small"><?= esc($obs['captured_at'] ?? '') ?></td>
                        <td><span class="badge text-bg-secondary"><?= esc($obs['source'] ?? '') ?></span></td>
                        <td class="small"><?= esc($obs['external_title'] ?? '') ?></td>
                        <td><?= money($obs['external_price'] ?? null) ?></td>
                        <td class="small"><?= esc($obs['equivalence_note'] ?? '') ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif ?>

<?php if (! empty($score['breakdown'])): ?>
    <div class="card">
        <div class="card-header bg-white fw-semibold">Composição do ranking</div>
        <div class="card-body small">
            <ul class="mb-0">
                <?php foreach ($score['breakdown'] as $key => $part): ?>
                    <li>
                        <strong><?= esc($key) ?></strong>:
                        <?= (int) ($part['points'] ?? 0) ?> pts
                        <?php if (! empty($part['label'])): ?> — <?= esc($part['label']) ?><?php endif ?>
                    </li>
                <?php endforeach ?>
            </ul>
        </div>
    </div>
<?php endif ?>
<?= $this->endSection() ?>

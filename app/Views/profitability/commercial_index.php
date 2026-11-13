<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$relBadge = static function (?string $rel): string {
    return match ($rel) {
        'confirmado' => 'success',
        'estimado'   => 'warning',
        default      => 'secondary',
    };
};
$scoreBadge = static function (array $score): string {
    if (! empty($score['proven_profit'])) {
        return 'success';
    }
    $total = (int) ($score['total'] ?? 0);
    if ($total >= 70) {
        return 'primary';
    }
    if ($total >= 50) {
        return 'warning';
    }

    return 'secondary';
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <p class="text-body-secondary mb-0">
            Ranking comercial dos produtos Dropify reais: preços-alvo com re-cotação ML, concorrência oficial e potencial vs lucro comprovado.
        </p>
    </div>
    <form method="post" action="<?= site_url('profitability/commercial/refresh') ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-arrow-repeat me-1"></i>Atualizar portfólio
        </button>
    </form>
</div>

<?php if (! empty($cepPending)): ?>
    <div class="alert alert-warning">
        <strong>Pendência:</strong> CEP real de expedição dos fornecedores Dropify em São Paulo ainda não confirmado.
        Cotações de frete com endereço da VITRINEHUB <em>não</em> representam necessariamente o custo real do fornecedor.
    </div>
<?php endif ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
            <tr>
                <th>#</th>
                <th>Produto</th>
                <th>Score</th>
                <th>Tipo</th>
                <th>Preço 20%</th>
                <th>Mediana ML</th>
                <th>Competitivo?</th>
                <th>Confiabilidade</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="9" class="text-body-secondary">Nenhum produto Dropify encontrado. Importe o CSV primeiro.</td></tr>
            <?php endif ?>
            <?php foreach ($rows as $i => $row): ?>
                <?php
                $product = $row['product'] ?? [];
                $score   = $row['score'] ?? [];
                $target20 = null;
                foreach ($row['targets'] ?? [] as $t) {
                    if (isset($t['target_margin_pct']) && (string) $t['target_margin_pct'] === '20.00') {
                        $target20 = $t;
                        break;
                    }
                }
                $stats = $row['competition']['stats'] ?? [];
                $compBreakdown = $score['breakdown']['competitive'] ?? [];
                $pid = (int) ($product['id'] ?? 0);
                ?>
                <tr>
                    <td class="text-body-secondary"><?= $i + 1 ?></td>
                    <td>
                        <div class="fw-semibold"><?= esc($product['name'] ?? '') ?></div>
                        <div class="small text-body-secondary"><?= esc($product['sku'] ?? '') ?></div>
                        <?php if (! empty($row['error'])): ?>
                            <div class="small text-danger"><?= esc($row['error']) ?></div>
                        <?php else: ?>
                            <div class="small text-body-secondary"><?= esc($score['recommendation'] ?? '') ?></div>
                        <?php endif ?>
                    </td>
                    <td><span class="badge text-bg-<?= $scoreBadge($score) ?>"><?= (int) ($score['total'] ?? 0) ?></span></td>
                    <td class="small">
                        <?= ! empty($score['proven_profit'])
                            ? '<span class="badge text-bg-success">lucro comprovado*</span>'
                            : '<span class="badge text-bg-secondary">potencial comercial</span>' ?>
                    </td>
                    <td>
                        <?= money($target20['sale_price'] ?? null) ?>
                        <?php if ($target20): ?>
                            <div><span class="badge text-bg-<?= $relBadge($target20['guarantee'] ?? null) ?>"><?= esc($target20['guarantee'] ?? '—') ?></span></div>
                        <?php endif ?>
                    </td>
                    <td><?= money($stats['median'] ?? null) ?></td>
                    <td class="small"><?= esc($compBreakdown['label'] ?? '—') ?></td>
                    <td><span class="badge text-bg-<?= $relBadge($score['data_reliability'] ?? null) ?>"><?= esc($score['data_reliability'] ?? '—') ?></span></td>
                    <td class="text-nowrap">
                        <?php if ($pid > 0): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= site_url('profitability/commercial/' . $pid) ?>">Detalhe</a>
                            <?php if (($row['intelligence'] ?? null) === null): ?>
                                <form method="post" action="<?= site_url('profitability/commercial/' . $pid . '/refresh') ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="redirect_to" value="list">
                                    <button type="submit" class="btn btn-sm btn-primary">Cotar</button>
                                </form>
                            <?php else: ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('profitability/commercial/' . $pid . '/ops') ?>">Ops</a>
                            <?php endif ?>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<p class="small text-body-secondary mt-2 mb-0">
    * “Lucro comprovado” exige classificação rentável com custos essenciais confirmados. Enquanto o frete for estimado, o resultado permanece potencial comercial.
</p>
<?= $this->endSection() ?>

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
$profitBadge = static function (array $score): string {
    return match ($score['label'] ?? '') {
        'rentabilidade_comprovada' => 'success',
        'rentabilidade_indicativa' => 'primary',
        'margem_negativa'          => 'danger',
        default                    => 'secondary',
    };
};
$profitLabel = static function (array $score): string {
    return match ($score['label'] ?? '') {
        'rentabilidade_comprovada' => 'rentabilidade comprovada',
        'rentabilidade_indicativa' => 'rentabilidade indicativa',
        'margem_negativa'          => 'margem negativa',
        default                    => 'potencial comercial',
    };
};
$renderTable = static function (array $rows, string $mode) use ($relBadge, $profitBadge, $profitLabel): void {
    ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
            <tr>
                <th>#</th>
                <th>Produto</th>
                <?php if ($mode === 'viability'): ?>
                    <th>Viabilidade</th>
                    <th>Margem fin. %</th>
                    <th>Indicativa %</th>
                    <th>Score comercial</th>
                <?php else: ?>
                    <th>Score comercial</th>
                    <th>Margem fin. %</th>
                    <th>Viável?</th>
                <?php endif ?>
                <th>Preço 20%</th>
                <th>Rentabilidade</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="9" class="text-body-secondary">Nenhum produto Dropify encontrado.</td></tr>
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
                $pid = (int) ($product['id'] ?? 0);
                $viable = ! empty($score['financially_viable']);
                ?>
                <tr class="<?= ($mode === 'viability' && ! $viable) ? 'table-danger' : '' ?>">
                    <td class="text-body-secondary"><?= $i + 1 ?></td>
                    <td>
                        <div class="fw-semibold"><?= esc($product['name'] ?? '') ?></div>
                        <div class="small text-body-secondary"><?= esc($product['sku'] ?? '') ?></div>
                        <div class="small text-body-secondary"><?= esc($score['recommendation'] ?? '') ?></div>
                    </td>
                    <?php if ($mode === 'viability'): ?>
                        <td>
                            <?php if (! empty($score['proven_profit'])): ?>
                                <span class="badge text-bg-success">comprovada</span>
                            <?php elseif (! empty($score['indicative_rentable'])): ?>
                                <span class="badge text-bg-primary">indicativa</span>
                            <?php elseif ($viable): ?>
                                <span class="badge text-bg-warning">margem ≥ 0</span>
                            <?php else: ?>
                                <span class="badge text-bg-danger">não recomendado</span>
                            <?php endif ?>
                            <div class="small">fin. <?= (int) ($score['financial_score'] ?? 0) ?> pts</div>
                        </td>
                        <td class="fw-semibold"><?= percent_format($score['financial_margin_pct'] ?? null, 2) ?></td>
                        <td><?= percent_format($score['indicative_margin_pct'] ?? null, 2) ?></td>
                        <td><?= (int) ($score['commercial_score'] ?? $score['total'] ?? 0) ?></td>
                    <?php else: ?>
                        <td><span class="badge text-bg-secondary"><?= (int) ($score['commercial_score'] ?? $score['total'] ?? 0) ?></span></td>
                        <td><?= percent_format($score['financial_margin_pct'] ?? null, 2) ?></td>
                        <td><?= $viable ? 'sim' : 'não' ?></td>
                    <?php endif ?>
                    <td>
                        <?= money($target20['sale_price'] ?? null) ?>
                        <?php if ($target20): ?>
                            <div><span class="badge text-bg-<?= $relBadge($target20['guarantee'] ?? null) ?>"><?= esc($target20['guarantee'] ?? '—') ?></span></div>
                        <?php endif ?>
                    </td>
                    <td><span class="badge text-bg-<?= $profitBadge($score) ?>"><?= esc($profitLabel($score)) ?></span></td>
                    <td class="text-nowrap">
                        <?php if ($pid > 0): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= site_url('profitability/commercial/' . $pid) ?>">Detalhe</a>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('profitability/commercial/' . $pid . '/ops') ?>">Ops</a>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <p class="text-body-secondary mb-0">
        Dois rankings distintos: <strong>recomendação de venda</strong> (viabilidade financeira) e <strong>potencial comercial</strong> (mercado/estoque/logística).
        Margem negativa nunca sobe como melhor recomendação de venda.
    </p>
    <form method="post" action="<?= site_url('profitability/commercial/refresh') ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-arrow-repeat me-1"></i>Atualizar portfólio
        </button>
    </form>
</div>

<?php if (! empty($cepPending)): ?>
    <div class="alert alert-warning">
        <strong>Pendência:</strong> CEP real de expedição dos fornecedores Dropify ainda não confirmado documentalmente.
        Cadastre por fornecedor/CD ou por SKU. Frete sem CEP confirmado permanece <em>indicativo</em>.
    </div>
<?php endif ?>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold">1) Recomendação de venda — viabilidade financeira</div>
    <?php $renderTable($viabilityRows ?? [], 'viability'); ?>
</div>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold">2) Potencial comercial — não é ordem de venda</div>
    <div class="card-body py-2 small text-body-secondary">
        Ordena por fit de mercado (concorrência, estoque, capital, logística, sazonalidade, confiabilidade). Não promove produto com margem negativa.
    </div>
    <?php $renderTable($commercialRows ?? [], 'commercial'); ?>
</div>

<p class="small text-body-secondary mb-0">
    <strong>Rentabilidade comprovada</strong> exige tarifa e frete confirmados.
    <strong>Rentabilidade indicativa</strong> usa margem do preço-alvo 20% com frete/estimativas ainda abertas.
    Score comercial ≠ lucro.
</p>
<?= $this->endSection() ?>

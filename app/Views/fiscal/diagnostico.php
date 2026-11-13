<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$r = $report ?? [];
$libs = $r['libraries'] ?? [];
$exts = $r['extensions'] ?? [];
$env = $r['environment'] ?? [];
$company = $r['company'] ?? [];
$tax = $r['tax'] ?? [];
$cert = $r['certificate'] ?? [];
$acc = $r['accreditation'] ?? [];
$homolog = $r['homologation'] ?? [];
$storage = $r['storage'] ?? [];
$blockers = $r['blockers'] ?? [];
$okBadge = static fn (bool $ok): string => $ok
    ? '<span class="badge text-bg-success">OK</span>'
    : '<span class="badge text-bg-warning">Pendente</span>';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h2 class="h5 mb-1">Diagnóstico fiscal</h2>
        <div class="small text-body-secondary">
            Verificação técnica do emissor próprio (NFePHP → SEFAZ).
            Cadastros locais não comprovam credenciamento na SEFAZ.
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-sm btn-light" href="<?= site_url('fiscal') ?>">Painel NF-e</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('fiscal/company') ?>">Empresa / A1</a>
        <form method="post" action="<?= site_url('fiscal/diagnostico/locks') ?>"><?= csrf_field() ?>
            <button class="btn btn-sm btn-outline-danger" type="submit">Reafirmar bloqueio de emissão real</button>
        </form>
    </div>
</div>

<?php if (! empty($forced)): ?>
    <div class="alert alert-warning small">Flags perigosas estavam ligadas e foram desligadas automaticamente: <?= esc(implode(', ', $forced)) ?></div>
<?php endif ?>

<div class="alert <?= ! empty($homolog['ready']) ? 'alert-success' : 'alert-secondary' ?> small">
    <?php if (! empty($homolog['ready'])): ?>
        Pronto tecnicamente para testes de <strong>homologação</strong> SEFAZ (emissão real continua bloqueada).
    <?php else: ?>
        Ainda há pendências antes de transmitir em homologação.
    <?php endif ?>
</div>

<?php if ($blockers !== []): ?>
    <div class="card mb-3 border-warning">
        <div class="card-header bg-white fw-semibold">Pendências que impedem a emissão</div>
        <ul class="list-group list-group-flush">
            <?php foreach ($blockers as $item): ?>
                <li class="list-group-item small"><?= esc($item) ?></li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Biblioteca NFePHP</div>
            <div class="card-body small">
                <dl class="dl-compact row mb-0">
                    <dt class="col-5">sped-nfe</dt>
                    <dd class="col-7"><?= ! empty($libs['sped_nfe_installed']) ? 'instalada' : 'ausente' ?> · <?= esc($libs['versions']['nfephp-org/sped-nfe'] ?? '—') ?></dd>
                    <dt class="col-5">sped-da</dt>
                    <dd class="col-7"><?= esc($libs['versions']['nfephp-org/sped-da'] ?? '—') ?></dd>
                    <dt class="col-5">sped-common</dt>
                    <dd class="col-7"><?= esc($libs['versions']['nfephp-org/sped-common'] ?? '—') ?></dd>
                    <dt class="col-5">Make / Tools / DANFE</dt>
                    <dd class="col-7">
                        <?= ! empty($libs['make_available']) ? 'Make' : '—' ?>
                        / <?= ! empty($libs['sped_nfe_installed']) ? 'Tools' : '—' ?>
                        / <?= ! empty($libs['danfe_available']) ? 'DANFE' : '—' ?>
                    </dd>
                    <dt class="col-5">Schemas PL_009_V4</dt>
                    <dd class="col-7"><?= $okBadge(! empty($libs['schemes_pl009_v4'])) ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Extensões PHP (SAPI <?= esc($env['sapi'] ?? '') ?> · <?= esc($env['php_version'] ?? '') ?>)</div>
            <div class="card-body small">
                <div class="mb-2">SOAP: <?= $okBadge(! empty($exts['soap']) && ! empty($exts['soap_client'])) ?></div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach (($exts['items'] ?? []) as $name => $ok): ?>
                        <span class="badge text-bg-<?= $ok ? 'success' : 'danger' ?>"><?= esc($name) ?></span>
                    <?php endforeach ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Ambiente e travas</div>
            <div class="card-body small">
                <dl class="dl-compact row mb-0">
                    <dt class="col-6">Configurado</dt><dd class="col-6"><?= esc($env['configured'] ?? '') ?></dd>
                    <dt class="col-6">Efetivo</dt><dd class="col-6"><?= esc($env['effective'] ?? '') ?> (tpAmb=<?= (int) ($env['tpAmb'] ?? 2) ?>)</dd>
                    <dt class="col-6">Emissão real</dt>
                    <dd class="col-6"><?= ! empty($env['real_emission_authorized']) ? 'autorizada' : 'bloqueada' ?></dd>
                    <dt class="col-6">XML → Mercado Livre</dt>
                    <dd class="col-6"><?= ! empty($env['ml_xml_send_authorized']) ? 'autorizado' : 'bloqueado' ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Empresa e tributação</div>
            <div class="card-body small">
                <dl class="dl-compact row mb-0">
                    <dt class="col-5">Cadastro</dt>
                    <dd class="col-7"><?= $okBadge(! empty($company['complete'])) ?> <?= esc($company['legal_name'] ?? 'não cadastrada') ?></dd>
                    <dt class="col-5">Documento</dt><dd class="col-7"><?= esc($company['document'] ?? '—') ?></dd>
                    <dt class="col-5">Tributação</dt>
                    <dd class="col-7">
                        <?= $okBadge(! empty($tax['validated']) && ($tax['missing_keys'] ?? []) === []) ?>
                        <?= ! empty($tax['validated']) ? 'validada pelo contador' : 'sem validação' ?>
                    </dd>
                    <?php if (($tax['missing_keys'] ?? []) !== []): ?>
                        <dt class="col-5">Faltando</dt><dd class="col-7"><?= esc(implode(', ', $tax['missing_keys'])) ?></dd>
                    <?php endif ?>
                </dl>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Certificado A1</div>
            <div class="card-body small">
                <p class="mb-2"><?= $okBadge(! empty($cert['compatible'])) ?> <?= esc($cert['message'] ?? '') ?></p>
                <?php if (! empty($cert['present'])): ?>
                    <dl class="dl-compact row mb-0">
                        <dt class="col-4">Titular</dt><dd class="col-8"><?= esc($cert['subject'] ?? '—') ?></dd>
                        <dt class="col-4">Validade</dt>
                        <dd class="col-8"><?= esc(($cert['valid_from'] ?? '—') . ' → ' . ($cert['valid_to'] ?? '—')) ?></dd>
                        <dt class="col-4">Armazenamento</dt>
                        <dd class="col-8"><?= ! empty($cert['storage_private']) ? 'privado (writable/fiscal)' : 'verificar' ?></dd>
                    </dl>
                <?php else: ?>
                    <p class="mb-0 text-body-secondary">Use <a href="<?= site_url('fiscal/company') ?>">Empresa fiscal</a> para enviar o .pfx/.p12. Não envie o arquivo por chat.</p>
                <?php endif ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Credenciamento (declarado)</div>
            <div class="card-body small">
                <p class="mb-2"><?= $okBadge(! empty($acc['declared_by_admin'])) ?> <?= ! empty($acc['declared_by_admin']) ? 'Administrador declarou credenciamento' : 'Não declarado' ?></p>
                <p class="text-body-secondary mb-2"><?= esc($acc['disclaimer'] ?? '') ?></p>
                <?php if (($acc['note'] ?? '') !== ''): ?>
                    <p class="mb-2">Observação: <?= esc($acc['note']) ?></p>
                <?php endif ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('settings') ?>">Atualizar em Configurações → Fiscal</a>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold">Armazenamento fiscal</div>
    <div class="card-body small row g-2">
        <?php foreach (['certificates' => 'Certificados', 'xml' => 'XML', 'danfe' => 'DANFE'] as $key => $label): ?>
            <?php $row = $storage[$key] ?? []; ?>
            <div class="col-md-4">
                <?= $okBadge(! empty($row['ok'])) ?> <?= esc($label) ?>
                <div class="text-body-secondary"><?= ! empty($row['writable']) ? 'gravável' : 'sem escrita / ausente' ?></div>
            </div>
        <?php endforeach ?>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold">Checklist de homologação</div>
    <ul class="list-group list-group-flush small">
        <?php foreach (($homolog['checks'] ?? []) as $key => $ok): ?>
            <li class="list-group-item d-flex justify-content-between">
                <span><?= esc($key) ?></span>
                <?= $okBadge((bool) $ok) ?>
            </li>
        <?php endforeach ?>
    </ul>
    <?php if (($homolog['pending'] ?? []) !== []): ?>
        <div class="card-footer bg-white small">
            <div class="fw-semibold mb-1">Ações suas / do contador</div>
            <ul class="mb-0">
                <?php foreach ($homolog['pending'] as $p): ?>
                    <li><?= esc($p) ?></li>
                <?php endforeach ?>
            </ul>
        </div>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

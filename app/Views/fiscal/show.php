<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$labels = $labels ?? [];
$statusLabel = $labels[$invoice['status']] ?? $invoice['status'];
$gate = $gate ?? null;
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h2 class="h5 mb-1">NF-e #<?= (int) $invoice['id'] ?></h2>
        <div class="small text-body-secondary">
            <?= esc($statusLabel) ?>
            · <?= esc($invoice['environment']) ?>
            · série <?= (int) $invoice['serie'] ?> nº <?= (int) $invoice['number'] ?>
        </div>
    </div>
    <a class="btn btn-sm btn-light" href="<?= site_url('fiscal') ?>">Voltar</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Documento</div>
            <div class="card-body">
                <dl class="dl-compact row small mb-0">
                    <dt class="col-4">Pedido</dt>
                    <dd class="col-8">
                        <?php if (! empty($invoice['order_id'])): ?>
                            <a href="<?= site_url('orders/' . $invoice['order_id']) ?>"><?= esc($invoice['external_order_id'] ?? ('#' . $invoice['order_id'])) ?></a>
                        <?php else: ?>—<?php endif ?>
                    </dd>
                    <dt class="col-4">Shipping</dt><dd class="col-8"><?= esc($invoice['shipping_id'] ?? '—') ?></dd>
                    <dt class="col-4">Chave</dt><dd class="col-8"><code class="small"><?= esc($invoice['access_key'] ?? '—') ?></code></dd>
                    <dt class="col-4">Protocolo</dt><dd class="col-8"><?= esc($invoice['protocol'] ?? '—') ?></dd>
                    <dt class="col-4">SEFAZ</dt><dd class="col-8"><?= esc(($invoice['sefaz_status_code'] ?? '') . ' — ' . ($invoice['sefaz_message'] ?? '')) ?></dd>
                    <dt class="col-4">Emitida</dt><dd class="col-8"><?= esc($invoice['issued_at'] ?? '—') ?></dd>
                    <dt class="col-4">Autorizada</dt><dd class="col-8"><?= esc($invoice['authorized_at'] ?? '—') ?></dd>
                    <dt class="col-4">Envio ML</dt><dd class="col-8"><?= esc(($invoice['ml_send_status'] ?? '—') . ' ' . ($invoice['ml_send_message'] ?? '')) ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Ações</div>
            <div class="card-body d-grid gap-2">
                <form method="post" action="<?= site_url('fiscal/' . $invoice['id'] . '/transmit') ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-primary w-100" type="submit" <?= ($gate && $gate->moduleEnabled()) ? '' : 'disabled' ?>>
                        Transmitir à SEFAZ (homologação por padrão)
                    </button>
                </form>
                <form method="post" action="<?= site_url('fiscal/' . $invoice['id'] . '/consultar') ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-secondary w-100" type="submit">Consultar autorização</button>
                </form>
                <form method="post" action="<?= site_url('fiscal/' . $invoice['id'] . '/danfe') ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-secondary w-100" type="submit">Gerar DANFE</button>
                </form>
                <div class="d-flex flex-wrap gap-2">
                    <?php if (! empty($invoice['xml_signed_path'])): ?>
                        <a class="btn btn-sm btn-light" href="<?= site_url('fiscal/' . $invoice['id'] . '/xml/signed') ?>">XML assinado</a>
                    <?php endif ?>
                    <?php if (! empty($invoice['xml_authorized_path'])): ?>
                        <a class="btn btn-sm btn-light" href="<?= site_url('fiscal/' . $invoice['id'] . '/xml/authorized') ?>">XML autorizado</a>
                    <?php endif ?>
                    <?php if (! empty($invoice['danfe_path'])): ?>
                        <a class="btn btn-sm btn-light" href="<?= site_url('fiscal/' . $invoice['id'] . '/danfe/download') ?>">Baixar DANFE</a>
                    <?php endif ?>
                </div>
                <hr>
                <form method="post" action="<?= site_url('fiscal/' . $invoice['id'] . '/cancelar') ?>" class="row g-2" data-confirm="Confirmar cancelamento na SEFAZ?">
                    <?= csrf_field() ?>
                    <div class="col-12">
                        <label class="form-label">Justificativa de cancelamento (mín. 15 caracteres)</label>
                        <textarea name="justificativa" class="form-control form-control-sm" rows="2" required></textarea>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-sm btn-outline-danger" type="submit">Cancelar NF-e</button>
                    </div>
                </form>
                <form method="post" action="<?= site_url('fiscal/' . $invoice['id'] . '/ml-send') ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-dark w-100" type="submit" title="Bloqueado em homologação">
                        Enviar XML ao Mercado Livre
                    </button>
                    <div class="form-text">Bloqueado até emissão real autorizada. Homologação nunca envia XML ao ML.</div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold">Histórico de eventos</div>
    <div class="list-group list-group-flush">
        <?php if ($events === []): ?>
            <div class="list-group-item text-body-secondary small">Sem eventos.</div>
        <?php endif ?>
        <?php foreach ($events as $event): ?>
            <div class="list-group-item small">
                <div class="fw-semibold"><?= esc($event['event_type']) ?> · <?= esc($event['created_at'] ?? '') ?></div>
                <div><?= esc($event['message'] ?? '') ?></div>
            </div>
        <?php endforeach ?>
    </div>
</div>
<?= $this->endSection() ?>

<?php

use App\Models\WebhookEventModel;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreNotificationService;

?>
<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?= $this->include('mercadolivre/_tabs') ?>

<div class="alert alert-light border small">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <i class="bi bi-info-circle text-primary me-1"></i>
            Cadastre <code><?= esc($webhookUrl) ?></code> como URL de notificações do aplicativo no Mercado Livre e assine os tópicos
            <code><?= MercadoLivreNotificationService::TOPIC_ORDERS ?></code> e <code><?= MercadoLivreNotificationService::TOPIC_ITEMS ?></code>.
            O endereço precisa ser público (HTTPS).
            Cada notificação é só gravada ao chegar; o pedido ou anúncio é consultado depois, pelo comando <code>php spark meli:process-notifications</code> (agendado) ou pelo botão ao lado.
            <?php if (! $ipFilter): ?>
                <div class="mt-1 text-body-secondary">Filtro de IP de origem desligado (MELI_WEBHOOK_IPS no .env).</div>
            <?php endif ?>
        </div>
        <form action="<?= site_url('marketplaces/mercadolivre/notifications/process') ?>" method="post">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-play-circle"></i> Processar fila (<?= (int) ($counts[WebhookEventModel::STATUS_PENDING] ?? 0) ?>)</button>
        </form>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3 small">
    <?php foreach (WebhookEventModel::STATUS_LABELS as $status => $label): ?>
        <a href="<?= site_url('marketplaces/mercadolivre/notifications?status=' . $status) ?>" class="text-decoration-none"><?= notification_status_badge($status) ?> <span class="text-body-secondary"><?= (int) ($counts[$status] ?? 0) ?></span></a>
    <?php endforeach ?>
</div>

<div class="card filters-card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-4 col-lg-3">
                <label class="form-label" for="q">Recurso</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>" placeholder="Ex.: /orders/2000001234567890">
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label" for="topic">Tópico</label>
                <input type="text" class="form-control form-control-sm" id="topic" name="topic" value="<?= esc($filters['topic'] ?? '', 'attr') ?>" placeholder="Ex.: orders_v2">
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Todos</option>
                    <?php foreach (WebhookEventModel::STATUS_LABELS as $value => $label): ?>
                        <option value="<?= esc($value, 'attr') ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
                <a class="btn btn-sm btn-light" href="<?= site_url('marketplaces/mercadolivre/notifications') ?>">Limpar</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <?php if ($events === []): ?>
        <?= view('partials/empty_state', ['icon' => 'bell', 'message' => $filters === [] ? 'Nenhuma notificação recebida ainda.' : 'Nenhuma notificação encontrada com esses filtros.']) ?>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Recebida</th>
                        <th>Tópico</th>
                        <th>Recurso</th>
                        <th>Conta</th>
                        <th class="text-center" title="Tentativas de entrega do Mercado Livre / de processamento aqui">Tentativas</th>
                        <th>Status</th>
                        <th>Resultado</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td class="small text-nowrap"><?= format_datetime($event['received_at']) ?></td>
                            <td><code><?= esc($event['topic']) ?></code></td>
                            <td class="small"><code><?= esc($event['resource']) ?></code></td>
                            <td class="small"><?= esc($event['account_name'] ?? 'usuário ' . $event['user_id']) ?></td>
                            <td class="text-center small"><?= (int) $event['attempts'] ?> / <?= (int) $event['process_attempts'] ?></td>
                            <td><?= notification_status_badge($event['status']) ?></td>
                            <td class="small"><?= esc($event['message'] ?? '—') ?><?= $event['processed_at'] ? '<div class="text-body-secondary">' . format_datetime($event['processed_at']) . '</div>' : '' ?></td>
                            <td class="text-end">
                                <?php if (in_array($event['status'], [WebhookEventModel::STATUS_FAILED, WebhookEventModel::STATUS_IGNORED], true)): ?>
                                    <form action="<?= site_url('marketplaces/mercadolivre/notifications/' . $event['id'] . '/retry') ?>" method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Devolver à fila"><i class="bi bi-arrow-counterclockwise"></i></button>
                                    </form>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?= $this->include('partials/pagination') ?>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

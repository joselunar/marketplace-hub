<?php

use App\Models\OrderModel;

?>
<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <p class="text-body-secondary small mb-0">
        Vendas recebidas pelas notificações do Mercado Livre ou pela importação manual.
        Nada é comprado automaticamente: o fornecedor só é acionado quando você usa <em>Enviar pedido ao fornecedor</em>.
    </p>
    <?php if ($accounts !== []): ?>
        <form action="<?= site_url('orders/import') ?>" method="post" class="d-flex gap-2 align-items-center">
            <?= csrf_field() ?>
            <?php if (count($accounts) > 1): ?>
                <select name="account" class="form-select form-select-sm" aria-label="Conta" required>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= (int) $account['id'] ?>"><?= esc($account['name']) ?></option>
                    <?php endforeach ?>
                </select>
            <?php else: ?>
                <input type="hidden" name="account" value="<?= (int) $accounts[0]['id'] ?>">
            <?php endif ?>
            <select name="days" class="form-select form-select-sm" aria-label="Período">
                <option value="1">Último dia</option>
                <option value="7" selected>Últimos 7 dias</option>
                <option value="30">Últimos 30 dias</option>
            </select>
            <button type="submit" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-cloud-download"></i> Importar pedidos</button>
        </form>
    <?php endif ?>
</div>

<div class="card filters-card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="q">Pedido, anúncio, SKU ou título</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>" placeholder="Ex.: 2000001234567890">
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label" for="fulfillment">Andamento</label>
                <select class="form-select form-select-sm" id="fulfillment" name="fulfillment">
                    <option value="">Todos</option>
                    <option value="awaiting_supplier" <?= ($filters['fulfillment'] ?? '') === 'awaiting_supplier' ? 'selected' : '' ?>>Aguardando fornecedor</option>
                    <?php foreach (OrderModel::FULFILLMENT_LABELS as $value => $label): ?>
                        <option value="<?= esc($value, 'attr') ?>" <?= ($filters['fulfillment'] ?? '') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label" for="status">Status no Mercado Livre</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Todos</option>
                    <?php foreach (OrderModel::STATUS_LABELS as $value => $label): ?>
                        <option value="<?= esc($value, 'attr') ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label" for="period">Período</label>
                <select class="form-select form-select-sm" id="period" name="period">
                    <option value="">Todos</option>
                    <?php foreach (['today' => 'Hoje', '7d' => 'Últimos 7 dias', '30d' => 'Últimos 30 dias'] as $value => $label): ?>
                        <option value="<?= $value ?>" <?= ($filters['period'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <?php if (count($accounts) > 1): ?>
                <div class="col-sm-3 col-lg-2">
                    <label class="form-label" for="account">Conta</label>
                    <select class="form-select form-select-sm" id="account" name="account">
                        <option value="">Todas</option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?= (int) $account['id'] ?>" <?= ($filters['account'] ?? '') === (string) $account['id'] ? 'selected' : '' ?>><?= esc($account['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            <?php endif ?>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
                <a class="btn btn-sm btn-light" href="<?= site_url('orders') ?>">Limpar</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <?php if ($orders === []): ?>
        <?= view('partials/empty_state', ['icon' => 'receipt', 'message' => $filters === []
            ? ($accounts === [] ? 'Nenhum pedido ainda. Conecte uma conta do Mercado Livre para receber as vendas.' : 'Nenhum pedido ainda. As vendas chegam pelas notificações do Mercado Livre ou pelo botão "Importar pedidos".')
            : 'Nenhum pedido encontrado com esses filtros.']) ?>
        <?php if ($filters === [] && $accounts === []): ?>
            <div class="text-center pb-4"><a href="<?= site_url('marketplaces/mercadolivre') ?>" class="btn btn-sm btn-primary">Conectar conta</a></div>
        <?php endif ?>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Pedido</th>
                        <th>Itens</th>
                        <th>Data</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Lucro estimado</th>
                        <th class="text-end">Margem</th>
                        <th>Mercado Livre</th>
                        <th>Andamento</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td class="text-nowrap">
                                <a href="<?= site_url('orders/' . $order['id']) ?>" class="fw-medium text-decoration-none"><?= esc($order['external_order_id']) ?></a>
                                <?php if (count($accounts) > 1): ?><div class="small text-body-secondary"><?= esc($order['account_name']) ?></div><?php endif ?>
                            </td>
                            <td>
                                <?= esc($order['first_item_title'] ?? '—') ?>
                                <?php if ((int) $order['items_count'] > 1): ?><span class="small text-body-secondary">+ <?= (int) $order['items_count'] - 1 ?> item(ns)</span><?php endif ?>
                            </td>
                            <td class="small text-nowrap"><?= format_datetime($order['ordered_at']) ?></td>
                            <td class="text-end text-nowrap fw-semibold"><?= money($order['total_amount']) ?></td>
                            <td class="text-end text-nowrap"><?= money($order['estimated_profit']) ?></td>
                            <td class="text-end"><?= percent_format($order['margin']) ?></td>
                            <td>
                                <?= order_status_badge($order['status']) ?>
                                <?php if ((int) $order['fraud_risk'] === 1): ?><?= badge('Risco de fraude', 'danger') ?><?php endif ?>
                            </td>
                            <td><?= fulfillment_badge($order['fulfillment_status']) ?></td>
                            <td class="text-end"><a href="<?= site_url('orders/' . $order['id']) ?>" class="btn btn-sm btn-outline-secondary" title="Visualizar"><i class="bi bi-eye"></i></a></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?= $this->include('partials/pagination') ?>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

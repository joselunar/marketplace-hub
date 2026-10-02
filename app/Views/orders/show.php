<?php

use App\Models\OrderModel;
use App\Models\SupplierOrderModel;

?>
<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$paid      = in_array($order['status'], OrderModel::PAID_STATUSES, true);
$fraud     = (int) $order['fraud_risk'] === 1;
$placed    = array_filter($supplierOrders, static fn (array $so): bool => in_array($so['status'], SupplierOrderModel::PLACED_STATUSES, true));
$canRealloc = $paid && ! $fraud && $placed === [];
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h2 class="h5 mb-1">Pedido <?= esc($order['external_order_id']) ?></h2>
        <div class="small text-body-secondary">
            <?= order_status_badge($order['status']) ?>
            <?= fulfillment_badge($order['fulfillment_status']) ?>
            · Conta <?= esc($order['account_name']) ?> · <?= format_datetime($order['ordered_at']) ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <form action="<?= site_url('orders/' . $order['id'] . '/sync') ?>" method="post"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> Atualizar do Mercado Livre</button></form>
        <?php if ($canRealloc): ?>
            <form action="<?= site_url('orders/' . $order['id'] . '/reallocate') ?>" method="post" data-confirm="Desfazer as reservas não enviadas e escolher os fornecedores de novo, com estoque e preços atuais?">
                <?= csrf_field() ?><button class="btn btn-sm btn-outline-primary"><i class="bi bi-shuffle"></i> Revalidar fornecedores</button>
            </form>
        <?php endif ?>
    </div>
</div>

<?php if ($fraud): ?>
    <div class="alert alert-danger small"><i class="bi bi-shield-exclamation me-1"></i> O Mercado Livre sinalizou <strong>risco de fraude</strong> nesta venda. A orientação oficial é não enviar a mercadoria e cancelar a venda.</div>
<?php elseif (! empty($order['issue'])): ?>
    <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i> <?= esc($order['issue']) ?></div>
<?php elseif (! $paid && ! in_array($order['status'], OrderModel::CANCELLED_STATUSES, true)): ?>
    <div class="alert alert-light border small"><i class="bi bi-hourglass-split me-1"></i> O fornecedor só é reservado depois que o pagamento for aprovado.</div>
<?php endif ?>

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Venda</div>
            <div class="card-body">
                <dl class="dl-compact row small mb-0">
                    <dt class="col-5">Status</dt><dd class="col-7"><?= order_status_badge($order['status']) ?><?= $order['status_detail'] ? ' <span class="text-body-secondary">' . esc($order['status_detail']) . '</span>' : '' ?></dd>
                    <?php if ($order['pack_id']): ?><dt class="col-5">Carrinho (pack)</dt><dd class="col-7"><?= esc($order['pack_id']) ?></dd><?php endif ?>
                    <dt class="col-5">Envio</dt><dd class="col-7"><?= esc($order['shipping_id'] ?? '—') ?></dd>
                    <dt class="col-5">Comprador (ID)</dt><dd class="col-7"><?= esc($order['buyer_external_id'] ?? '—') ?></dd>
                    <dt class="col-5">Criado em</dt><dd class="col-7"><?= format_datetime($order['ordered_at']) ?></dd>
                    <dt class="col-5">Atualizado no ML</dt><dd class="col-7"><?= format_datetime($order['external_updated_at']) ?></dd>
                    <dt class="col-5">Última leitura</dt><dd class="col-7"><?= relative_time($order['last_sync_at']) ?></dd>
                    <?php if ($order['tags']): ?>
                        <dt class="col-5">Tags</dt>
                        <dd class="col-7"><?php foreach (explode(',', $order['tags']) as $tag): ?><span class="badge text-bg-light border me-1"><?= esc($tag) ?></span><?php endforeach ?></dd>
                    <?php endif ?>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Valores e lucro estimado</div>
            <div class="card-body">
                <dl class="dl-compact row small mb-0">
                    <dt class="col-6">Total da venda</dt><dd class="col-6 fw-semibold"><?= money($order['total_amount']) ?> <?= esc($order['currency_id']) ?></dd>
                    <dt class="col-6">Valor pago</dt><dd class="col-6"><?= money($order['paid_amount']) ?></dd>
                    <dt class="col-6">Frete pago pelo comprador</dt><dd class="col-6"><?= money($order['shipping_amount']) ?></dd>
                    <dt class="col-6">Tarifas do Mercado Livre</dt>
                    <dd class="col-6"><?= money($order['marketplace_fees']) ?><?= (int) $order['fees_estimated'] === 1 ? ' <span class="badge text-bg-light border" title="O Mercado Livre informa a tarifa quando o pagamento é creditado; até lá usamos a comissão do anúncio ou das configurações.">estimada</span>' : '' ?></dd>
                    <dt class="col-6">Custo nos fornecedores</dt><dd class="col-6"><?= money($order['supplier_cost']) ?></dd>
                    <dt class="col-6">Lucro estimado</dt><dd class="col-6 fw-semibold"><?= money($order['estimated_profit']) ?></dd>
                    <dt class="col-6">Margem</dt><dd class="col-6"><?= percent_format($margin) ?></dd>
                </dl>
                <div class="small text-body-secondary mt-2">Inclui impostos e custos extras das configurações de precificação. O frete pago pelo vendedor ao Mercado Livre não entra, pois não vem no pedido.</div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold">Itens</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Produto</th>
                    <th class="text-center">Qtd.</th>
                    <th class="text-end">Preço unit.</th>
                    <th class="text-end">Tarifa</th>
                    <th>Fornecedor reservado</th>
                    <th class="text-end">Custo + frete</th>
                    <th class="text-end">Lucro estimado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <?= esc($item['title']) ?>
                            <div class="small text-body-secondary">
                                <?php if ($item['permalink']): ?>
                                    <a href="<?= esc($item['permalink'], 'attr') ?>" target="_blank" rel="noopener noreferrer"><?= esc($item['external_item_id']) ?></a>
                                <?php else: ?>
                                    <?= esc($item['external_item_id']) ?>
                                <?php endif ?>
                                <?= $item['variation_id'] !== '' ? ' · variação ' . esc($item['variation_id']) : '' ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($item['product_id'] !== null): ?>
                                <a href="<?= site_url('products/' . $item['product_id']) ?>"><?= esc($item['product_name']) ?></a> <code class="small"><?= esc($item['sku']) ?></code>
                            <?php else: ?>
                                <span class="text-danger-emphasis small">Não ligado a produto<?= $item['seller_sku'] ? ' (SKU ' . esc($item['seller_sku']) . ')' : '' ?></span>
                            <?php endif ?>
                        </td>
                        <td class="text-center"><?= (int) $item['quantity'] ?></td>
                        <td class="text-end text-nowrap"><?= money($item['unit_price']) ?></td>
                        <td class="text-end text-nowrap"><?= $item['sale_fee'] !== null ? money($item['sale_fee']) : '<span class="text-body-secondary small">estimada</span>' ?></td>
                        <td>
                            <?php if ($item['supplier_id'] !== null): ?>
                                <?= esc($item['supplier_name']) ?><?= $item['supplier_sku'] ? ' <code class="small">' . esc($item['supplier_sku']) . '</code>' : '' ?>
                            <?php else: ?>
                                <span class="text-body-secondary">—</span>
                            <?php endif ?>
                            <?php if ($item['allocation_note']): ?><div class="small text-warning-emphasis"><?= esc($item['allocation_note']) ?></div><?php endif ?>
                        </td>
                        <td class="text-end text-nowrap"><?= $item['supplier_cost'] !== null ? money($item['supplier_cost']) . ' + ' . money($item['supplier_shipping']) : '—' ?></td>
                        <td class="text-end text-nowrap fw-semibold"><?= money($item['estimated_profit']) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<h3 class="h6 mb-2">Pedidos aos fornecedores</h3>
<?php if ($supplierOrders === []): ?>
    <div class="card"><div class="card-body small text-body-secondary">Nenhum fornecedor reservado ainda.</div></div>
<?php endif ?>
<div class="row g-3">
    <?php foreach ($supplierOrders as $so): ?>
        <?php $transitions = SupplierOrderModel::TRANSITIONS[$so['status']] ?? []; ?>
        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><a href="<?= site_url('suppliers/' . $so['supplier_id']) ?>" class="text-decoration-none"><?= esc($so['supplier_name']) ?></a></span>
                    <?= supplier_order_badge($so['status']) ?>
                </div>
                <div class="card-body">
                    <dl class="dl-compact row small">
                        <dt class="col-5">Custo</dt><dd class="col-7 fw-semibold"><?= money($so['cost']) ?></dd>
                        <dt class="col-5">Pedido no fornecedor</dt><dd class="col-7"><?= esc($so['external_supplier_order_id'] ?? '—') ?></dd>
                        <dt class="col-5">Rastreio</dt><dd class="col-7"><?= esc($so['tracking_code'] ?? '—') ?></dd>
                        <?php if ($so['sent_at']): ?><dt class="col-5">Enviado</dt><dd class="col-7"><?= format_datetime($so['sent_at']) ?><?= $so['sent_by_name'] ? ' por ' . esc($so['sent_by_name']) : '' ?></dd><?php endif ?>
                        <?php if ($so['notes']): ?><dt class="col-5">Observações</dt><dd class="col-7"><?= esc($so['notes']) ?></dd><?php endif ?>
                    </dl>

                    <?php if ($so['status'] === SupplierOrderModel::STATUS_PENDING && $paid && ! $fraud): ?>
                        <form action="<?= site_url('orders/supplier-orders/' . $so['id'] . '/send') ?>" method="post" class="border-top pt-3" data-confirm="Confirmar o envio deste pedido para <?= esc($so['supplier_name'], 'attr') ?>? Sem integração própria, o sistema só registra o envio: a compra com o fornecedor é feita por você.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <div class="row g-2 mb-2">
                                <div class="col-sm-5">
                                    <label class="form-label small" for="ext-<?= (int) $so['id'] ?>">Nº do pedido no fornecedor</label>
                                    <input type="text" class="form-control form-control-sm" id="ext-<?= (int) $so['id'] ?>" name="external_supplier_order_id" maxlength="100" placeholder="Opcional">
                                </div>
                                <div class="col-sm-7">
                                    <label class="form-label small" for="notes-<?= (int) $so['id'] ?>">Observações</label>
                                    <input type="text" class="form-control form-control-sm" id="notes-<?= (int) $so['id'] ?>" name="notes" maxlength="1000" placeholder="Opcional">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-send"></i> Enviar pedido ao fornecedor</button>
                        </form>
                    <?php endif ?>

                    <?php if ($transitions !== [] && $so['status'] !== SupplierOrderModel::STATUS_PENDING): ?>
                        <form action="<?= site_url('orders/supplier-orders/' . $so['id']) ?>" method="post" class="border-top pt-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <div class="row g-2 mb-2">
                                <div class="col-sm-4">
                                    <label class="form-label small" for="status-<?= (int) $so['id'] ?>">Situação</label>
                                    <select class="form-select form-select-sm" id="status-<?= (int) $so['id'] ?>" name="status">
                                        <option value="<?= esc($so['status'], 'attr') ?>"><?= esc(SupplierOrderModel::STATUS_LABELS[$so['status']]) ?></option>
                                        <?php foreach ($transitions as $next): ?>
                                            <option value="<?= esc($next, 'attr') ?>"><?= esc(SupplierOrderModel::STATUS_LABELS[$next]) ?></option>
                                        <?php endforeach ?>
                                    </select>
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label small" for="track-<?= (int) $so['id'] ?>">Rastreio</label>
                                    <input type="text" class="form-control form-control-sm" id="track-<?= (int) $so['id'] ?>" name="tracking_code" maxlength="100" value="<?= esc($so['tracking_code'] ?? '', 'attr') ?>">
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label small" for="ext2-<?= (int) $so['id'] ?>">Nº no fornecedor</label>
                                    <input type="text" class="form-control form-control-sm" id="ext2-<?= (int) $so['id'] ?>" name="external_supplier_order_id" maxlength="100" value="<?= esc($so['external_supplier_order_id'] ?? '', 'attr') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small" for="notes2-<?= (int) $so['id'] ?>">Observações</label>
                                    <input type="text" class="form-control form-control-sm" id="notes2-<?= (int) $so['id'] ?>" name="notes" maxlength="1000" value="<?= esc($so['notes'] ?? '', 'attr') ?>">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-check2"></i> Salvar acompanhamento</button>
                        </form>
                    <?php elseif ($so['status'] === SupplierOrderModel::STATUS_PENDING): ?>
                        <form action="<?= site_url('orders/supplier-orders/' . $so['id']) ?>" method="post" class="mt-2" data-confirm="Cancelar este pedido ao fornecedor? A reserva será liberada.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <input type="hidden" name="status" value="<?= SupplierOrderModel::STATUS_CANCELLED ?>">
                            <input type="hidden" name="tracking_code" value="<?= esc($so['tracking_code'] ?? '', 'attr') ?>">
                            <input type="hidden" name="external_supplier_order_id" value="<?= esc($so['external_supplier_order_id'] ?? '', 'attr') ?>">
                            <input type="hidden" name="notes" value="<?= esc($so['notes'] ?? '', 'attr') ?>">
                            <button type="submit" class="btn btn-sm btn-link text-danger p-0">Cancelar pedido ao fornecedor</button>
                        </form>
                    <?php endif ?>
                </div>
            </div>
        </div>
    <?php endforeach ?>
</div>
<?= $this->endSection() ?>

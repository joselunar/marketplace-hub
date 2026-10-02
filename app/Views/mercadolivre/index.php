<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?= $this->include('mercadolivre/_tabs') ?>

<?php if (! $configured): ?>
    <div class="alert alert-warning">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Integração não configurada</div>
        Preencha no <code>.env</code>: <?= esc(implode(', ', $missing)) ?>. Os valores vêm do aplicativo criado no
        <a href="https://developers.mercadolivre.com.br/" target="_blank" rel="noopener noreferrer">portal de desenvolvedores do Mercado Livre</a>;
        a URL de retorno cadastrada lá deve ser exatamente <code><?= esc($integration['expected_redirect']) ?></code>.
    </div>
<?php elseif ($integration['redirect_uri'] !== $integration['expected_redirect']): ?>
    <div class="alert alert-warning small">
        <i class="bi bi-signpost me-1"></i> <code>MELI_REDIRECT_URI</code> (<?= esc($integration['redirect_uri']) ?>) é diferente da rota de retorno deste painel
        (<?= esc($integration['expected_redirect']) ?>). Use esse valor se a URL for atendida por um proxy ou túnel HTTPS que encaminha para esta rota.
    </div>
<?php endif ?>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="card">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Contas conectadas</span>
                <?php if (is_admin()): ?>
                    <form action="<?= site_url('marketplaces/mercadolivre/connect') ?>" method="post">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-success" <?= $configured ? '' : 'disabled' ?>><i class="bi bi-plug me-1"></i> Conectar conta</button>
                    </form>
                <?php endif ?>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Conta</th>
                        <th>Status</th>
                        <th>Access token</th>
                        <th>Vencimento</th>
                        <th>Última renovação</th>
                        <?php if (is_admin()): ?><th class="text-end">Ações</th><?php endif ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($accounts as $account): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= esc($account['name']) ?></div>
                                <div class="small text-body-secondary">ID <?= esc($account['external_user_id']) ?><?= $account['site_id'] ? ' · ' . esc($account['site_id']) : '' ?></div>
                                <?php if (! empty($account['last_error'])): ?>
                                    <div class="small text-danger mt-1"><i class="bi bi-exclamation-circle me-1"></i><?= esc($account['last_error']) ?></div>
                                <?php endif ?>
                            </td>
                            <td><?= marketplace_account_badge($account) ?></td>
                            <td>
                                <code class="small text-nowrap"><?= esc($account['access_token_masked']) ?></code>
                                <?php if ($account['status'] === 'connected' && ! $account['has_refresh_token']): ?>
                                    <div class="small text-warning">Sem refresh token</div>
                                <?php endif ?>
                            </td>
                            <td class="small">
                                <?php if ($account['token_expires_at']): ?>
                                    <?= format_datetime($account['token_expires_at']) ?>
                                    <div class="<?= $account['token_expired'] ? 'text-danger' : 'text-body-secondary' ?>"><?= esc(relative_time($account['token_expires_at'])) ?></div>
                                <?php else: ?>—<?php endif ?>
                            </td>
                            <td class="small">
                                <?= format_datetime($account['token_refreshed_at']) ?>
                                <?php if ($account['connected_by_name']): ?>
                                    <div class="text-body-secondary">conectada por <?= esc($account['connected_by_name']) ?></div>
                                <?php endif ?>
                            </td>
                            <?php if (is_admin()): ?>
                                <td class="text-end text-nowrap">
                                    <?php if ($account['status'] === 'connected'): ?>
                                        <form action="<?= site_url('marketplaces/mercadolivre/accounts/' . $account['id'] . '/refresh') ?>" method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Renovar token agora"><i class="bi bi-arrow-repeat"></i></button>
                                        </form>
                                    <?php else: ?>
                                        <form action="<?= site_url('marketplaces/mercadolivre/connect') ?>" method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-primary" <?= $configured ? '' : 'disabled' ?>><i class="bi bi-plug me-1"></i> Reconectar</button>
                                        </form>
                                    <?php endif ?>
                                    <?php if ($account['status'] !== 'disconnected'): ?>
                                        <form action="<?= site_url('marketplaces/mercadolivre/accounts/' . $account['id'] . '/disconnect') ?>" method="post" class="d-inline"
                                              data-confirm="Desconectar a conta <?= esc($account['name'], 'attr') ?>? Os tokens serão apagados e a permissão do aplicativo será revogada no Mercado Livre.">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Desconectar"><i class="bi bi-x-lg"></i></button>
                                        </form>
                                    <?php endif ?>
                                </td>
                            <?php endif ?>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php if ($accounts === []): ?>
                <?= view('partials/empty_state', ['icon' => 'shop', 'message' => 'Nenhuma conta conectada ainda.']) ?>
            <?php endif ?>
            <div class="card-footer bg-white small text-body-secondary">
                O access token vale 6 horas e é renovado automaticamente (ao usar a API e pelo comando <code>php spark meli:refresh-token</code> no cron).
                Os tokens ficam cifrados no banco e nunca são exibidos por completo.
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">Aplicativo</div>
            <div class="card-body">
                <dl class="dl-compact mb-0 small">
                    <dt>Client ID</dt><dd><code><?= esc($integration['client_id'] ?? 'não definido') ?></code></dd>
                    <dt>Client Secret</dt><dd><?= in_array('MELI_CLIENT_SECRET', $missing, true) ? 'não definido' : 'definido no .env' ?></dd>
                    <dt>URL de retorno</dt><dd class="text-break"><?= esc($integration['redirect_uri'] ?: '—') ?></dd>
                    <dt>Autorização</dt><dd><?= esc($integration['auth_host']) ?></dd>
                    <dt>PKCE</dt><dd><?= $integration['pkce'] ? 'S256 (ativo)' : 'desligado' ?></dd>
                    <dt>Site padrão</dt><dd><?= esc($integration['site_id']) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Atividade recente</span>
                <?php if (is_admin()): ?><a href="<?= site_url('logs?tab=sync') ?>" class="small">Ver logs</a><?php endif ?>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($recentLogs as $log): ?>
                    <li class="list-group-item small">
                        <div class="d-flex justify-content-between">
                            <span><?= sync_status_badge($log['status']) ?> <?= esc($log['action']) ?></span>
                            <span class="text-body-secondary"><?= format_datetime($log['started_at']) ?></span>
                        </div>
                        <div class="text-body-secondary mt-1"><?= esc($log['message'] ?? '') ?></div>
                    </li>
                <?php endforeach ?>
                <?php if ($recentLogs === []): ?>
                    <li class="list-group-item small text-body-secondary">Nenhuma atividade registrada.</li>
                <?php endif ?>
            </ul>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

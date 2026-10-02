<?php

use App\Models\JobModel;
use App\Models\SyncLogModel;

$taskStatus = static fn (?string $status): string => match ($status) {
    SyncLogModel::STATUS_SUCCESS => badge('OK', 'success'),
    SyncLogModel::STATUS_WARNING => badge('Com avisos', 'warning'),
    SyncLogModel::STATUS_ERROR   => badge('Falhou', 'danger'),
    default                      => badge('Nunca executada', 'secondary'),
};

?>
<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php if ($heartbeat['scheduler_late']): ?>
    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <?php if ($heartbeat['scheduler'] === null): ?>
            O agendador nunca foi executado neste servidor.
        <?php else: ?>
            O agendador não roda desde <?= format_datetime($heartbeat['scheduler']['last_run_at']) ?> (<?= relative_time($heartbeat['scheduler']['last_run_at']) ?>).
        <?php endif ?>
        Configure no cron, a cada minuto:
        <code class="d-block mt-1">* * * * * cd /caminho/do/projeto &amp;&amp; php spark schedule:run &amp;&amp; php spark jobs:work &gt;&gt; /dev/null 2&gt;&amp;1</code>
    </div>
<?php endif ?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-body-secondary small mb-1"><i class="bi bi-clock-history me-1"></i>Agendador</div>
                <div class="fw-semibold"><?= $heartbeat['scheduler'] ? relative_time($heartbeat['scheduler']['last_run_at']) : 'Nunca executado' ?></div>
                <div class="small text-body-secondary"><?= esc($heartbeat['scheduler']['last_message'] ?? 'php spark schedule:run') ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-body-secondary small mb-1"><i class="bi bi-cpu me-1"></i>Worker</div>
                <div class="fw-semibold"><?= $heartbeat['worker'] ? relative_time($heartbeat['worker']['last_run_at']) : 'Nunca executado' ?></div>
                <div class="small text-body-secondary"><?= esc($heartbeat['worker']['last_message'] ?? 'php spark jobs:work') ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-body-secondary small mb-1"><i class="bi bi-sliders me-1"></i>Atualização automática dos anúncios</div>
                <div class="small">
                    Estoque: <?= $autoStock ? badge('Ligada', 'success') : badge('Desligada', 'secondary') ?>
                    <span class="ms-2">Preço: <?= $autoPrice ? badge('Ligada', 'success') : badge('Desligada', 'secondary') ?></span>
                </div>
                <div class="small text-body-secondary mt-1">
                    <?php if (is_admin()): ?>
                        Altere em <a href="<?= site_url('settings') ?>">Configurações &gt; Sincronização</a>.
                    <?php else: ?>
                        Um administrador altera em Configurações.
                    <?php endif ?>
                    Preço abaixo da margem mínima nunca é enviado.
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-calendar-check me-1"></i>Tarefas agendadas</span>
        <span class="small text-body-secondary">Novas tentativas: até <?= (int) $config->maxAttempts ?>, com espera de <?= (int) ($config->retryBaseSeconds / 60) ?> min dobrando a cada falha</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Tarefa</th>
                    <th>Intervalo</th>
                    <th>Última execução</th>
                    <th>Resultado</th>
                    <th>Próxima</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tasks as $task): ?>
                    <tr>
                        <td><?= esc($task['label']) ?></td>
                        <td class="small text-nowrap"><?= $task['interval'] > 0 ? 'a cada ' . (int) $task['interval'] . ' min' : '—' ?></td>
                        <td class="small text-nowrap"><?= $task['last_run_at'] ? format_datetime($task['last_run_at']) : '—' ?></td>
                        <td class="small"><?= $taskStatus($task['last_status']) ?> <?= esc($task['last_message'] ?? '') ?></td>
                        <td class="small text-nowrap">
                            <?php if ($task['enabled']): ?>
                                <?= $task['next_run_at'] <= time() ? 'na próxima execução do cron' : relative_time(date('Y-m-d H:i:s', $task['next_run_at'])) ?>
                            <?php else: ?>
                                <span class="text-body-secondary" title="<?= esc($task['disabled_why'], 'attr') ?>"><i class="bi bi-pause-circle"></i> <?= esc($task['disabled_why']) ?></span>
                            <?php endif ?>
                        </td>
                        <td class="text-end">
                            <form action="<?= site_url('sync/tasks/' . $task['name'] . '/run') ?>" method="post" class="d-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap" <?= $task['enabled'] || $task['interval'] === 0 ? '' : 'disabled' ?> title="Enfileirar agora, sem esperar o intervalo"><i class="bi bi-play-fill"></i> Executar agora</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><i class="bi bi-truck me-1"></i>Fornecedores sincronizados por integração</div>
    <div class="card-body small">
        <?php if ($suppliers === []): ?>
            <p class="mb-0 text-body-secondary">
                Nenhum fornecedor ativo tem adapter de integração registrado em <code>Config\SupplierConnectors</code>.
                Fornecedores manuais e por CSV continuam sendo atualizados pela <a href="<?= site_url('imports') ?>">importação de arquivo</a>.
            </p>
        <?php else: ?>
            <ul class="mb-0">
                <?php foreach ($suppliers as $supplier): ?>
                    <li>
                        <a href="<?= site_url('suppliers/' . $supplier['id']) ?>"><?= esc($supplier['name']) ?></a>:
                        <?= $supplier['stock'] ? 'estoque' : '' ?><?= $supplier['stock'] && $supplier['prices'] ? ' e ' : '' ?><?= $supplier['prices'] ? 'custos' : '' ?>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <div class="d-flex flex-wrap gap-2 small">
        <?php foreach (JobModel::STATUS_LABELS as $status => $label): ?>
            <a href="<?= site_url('sync?status=' . $status) ?>" class="text-decoration-none"><?= job_status_badge($status) ?> <span class="text-body-secondary"><?= (int) ($counts[$status] ?? 0) ?></span></a>
        <?php endforeach ?>
    </div>
    <?php if (is_admin()): ?>
        <div class="d-flex gap-2">
            <form action="<?= site_url('sync/jobs/retry-failed') ?>" method="post" data-confirm="Devolver à fila todos os jobs com falha?">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-outline-secondary" <?= ($counts[JobModel::STATUS_FAILED] ?? 0) > 0 ? '' : 'disabled' ?>><i class="bi bi-arrow-counterclockwise"></i> Repetir falhas</button>
            </form>
            <form action="<?= site_url('sync/jobs/cancel-pending') ?>" method="post" data-confirm="Cancelar todos os jobs que aguardam na fila?">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-outline-danger" <?= ($counts[JobModel::STATUS_PENDING] ?? 0) > 0 ? '' : 'disabled' ?>><i class="bi bi-x-circle"></i> Cancelar fila</button>
            </form>
        </div>
    <?php endif ?>
</div>

<div class="card filters-card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-4 col-lg-3">
                <label class="form-label" for="q">Referência</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>" placeholder="Ex.: MLB1234567890 ou nome do fornecedor">
            </div>
            <div class="col-sm-4 col-lg-3">
                <label class="form-label" for="type">Tipo</label>
                <select class="form-select form-select-sm" id="type" name="type">
                    <option value="">Todos</option>
                    <?php foreach ($types as $value => $label): ?>
                        <option value="<?= esc($value, 'attr') ?>" <?= ($filters['type'] ?? '') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Todos</option>
                    <?php foreach (JobModel::STATUS_LABELS as $value => $label): ?>
                        <option value="<?= esc($value, 'attr') ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
                <a class="btn btn-sm btn-light" href="<?= site_url('sync') ?>">Limpar</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <?php if ($jobs === []): ?>
        <?= view('partials/empty_state', ['icon' => 'arrow-repeat', 'message' => $filters === [] ? 'Nenhum job na fila ainda.' : 'Nenhum job encontrado com esses filtros.']) ?>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Job</th>
                        <th>Origem</th>
                        <th>Criado</th>
                        <th class="text-center">Tentativas</th>
                        <th>Status</th>
                        <th>Resultado</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $job): ?>
                        <tr>
                            <td class="small text-body-secondary"><?= (int) $job['id'] ?></td>
                            <td>
                                <?= esc($types[$job['type']] ?? $job['type']) ?>
                                <?php if ($job['reference']): ?><div class="small text-body-secondary"><?= esc($job['reference']) ?></div><?php endif ?>
                            </td>
                            <td class="small"><?= esc(JobModel::SOURCE_LABELS[$job['source']] ?? $job['source']) ?><?= $job['created_by_name'] ? '<div class="text-body-secondary">' . esc($job['created_by_name']) . '</div>' : '' ?></td>
                            <td class="small text-nowrap"><?= format_datetime($job['created_at']) ?></td>
                            <td class="text-center small"><?= (int) $job['attempts'] ?> / <?= (int) $job['max_attempts'] ?></td>
                            <td><?= job_status_badge($job['status']) ?></td>
                            <td class="small">
                                <?php if ($job['status'] === JobModel::STATUS_PENDING && strtotime($job['available_at']) > time()): ?>
                                    <div class="text-body-secondary">Nova tentativa <?= relative_time($job['available_at']) ?></div>
                                <?php endif ?>
                                <?php if ($job['last_error'] && $job['status'] !== JobModel::STATUS_COMPLETED): ?>
                                    <div class="text-danger"><?= esc($job['last_error']) ?></div>
                                <?php endif ?>
                                <?= $job['result'] ? esc($job['result']) : '' ?>
                                <?php if ($job['finished_at']): ?><div class="text-body-secondary"><?= format_datetime($job['finished_at']) ?></div><?php endif ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php if ($job['status'] === JobModel::STATUS_FAILED): ?>
                                    <form action="<?= site_url('sync/jobs/' . $job['id'] . '/retry') ?>" method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Executar de novo"><i class="bi bi-arrow-counterclockwise"></i></button>
                                    </form>
                                <?php elseif ($job['status'] === JobModel::STATUS_PENDING): ?>
                                    <form action="<?= site_url('sync/jobs/' . $job['id'] . '/cancel') ?>" method="post" class="d-inline" data-confirm="Cancelar este job?">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancelar"><i class="bi bi-x-lg"></i></button>
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

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$ready = is_array($context ?? null) && ! empty($context['ready']);
$options = is_array($context['options'] ?? null) ? $context['options'] : [];
$prefs = is_array($context['preferences'] ?? null) ? $context['preferences'] : null;
?>

<p class="text-body-secondary mb-3">
    Consulta somente leitura de <code>GET /users/{id}/shipping_preferences</code>.
    Mode e tipo logístico só entram no cálculo se vierem do .env, desta confirmação ou do único/default ativo da conta — nunca inventados.
</p>

<?php if ($error): ?>
    <div class="alert alert-warning"><?= esc($error) ?></div>
<?php endif ?>

<?php if ($account): ?>
    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">Conta</div>
        <div class="card-body small">
            <?= esc($account['nickname'] ?? $account['external_user_id'] ?? '') ?>
            · site <?= esc($account['site_id'] ?? '') ?>
            · status <?= esc($account['status'] ?? '') ?>
        </div>
    </div>
<?php endif ?>

<?php if ($context): ?>
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white fw-semibold">Contexto de cotação</div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-sm-4">Pronto</dt>
                        <dd class="col-sm-8"><?= $ready ? 'Sim' : 'Não' ?></dd>
                        <dt class="col-sm-4">Origem</dt>
                        <dd class="col-sm-8"><code><?= esc($context['source'] ?? '') ?></code></dd>
                        <dt class="col-sm-4">Mode</dt>
                        <dd class="col-sm-8"><?= esc($context['shipping_mode'] ?? '—') ?></dd>
                        <dt class="col-sm-4">Logistic type</dt>
                        <dd class="col-sm-8"><?= esc($context['logistic_type'] ?? '—') ?></dd>
                        <dt class="col-sm-4">Detalhe</dt>
                        <dd class="col-sm-8"><?= esc($context['reason'] ?? '') ?></dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white fw-semibold">Preferências lidas da API</div>
                <div class="card-body small">
                    <?php if ($prefs === null): ?>
                        <p class="text-body-secondary mb-0">Preferências não disponíveis.</p>
                    <?php else: ?>
                        <p class="mb-1"><strong>Modes:</strong> <?= esc(implode(', ', $prefs['modes'] ?? []) ?: '—') ?></p>
                        <p class="mb-1"><strong>Tags:</strong> <?= esc(implode(', ', $prefs['tags'] ?? []) ?: '—') ?></p>
                        <p class="mb-1"><strong>Retirada local:</strong> <?= isset($prefs['local_pick_up']) ? ($prefs['local_pick_up'] ? 'sim' : 'não') : '—' ?></p>
                        <p class="mb-2 text-body-secondary">Consultado em <?= esc($prefs['fetched_at'] ?? '') ?></p>
                        <ul class="mb-0">
                            <?php foreach ($options as $option): ?>
                                <li>
                                    <code><?= esc($option['mode']) ?>/<?= esc($option['type']) ?></code>
                                    <?php if (! empty($option['default'])): ?> <span class="badge text-bg-primary">default</span><?php endif ?>
                                </li>
                            <?php endforeach ?>
                            <?php if ($options === []): ?>
                                <li class="text-body-secondary">Nenhum tipo ativo listado.</li>
                            <?php endif ?>
                        </ul>
                    <?php endif ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">Confirmar combinação para cotação</div>
        <div class="card-body">
            <?php if ($options === []): ?>
                <p class="text-body-secondary mb-0">Sem opções ativas para confirmar. O frete do vendedor permanece desconhecido até a API informar um contexto utilizável.</p>
            <?php else: ?>
                <form method="post" action="<?= site_url('profitability/logistics') ?>" class="row g-3 align-items-end">
                    <?= csrf_field() ?>
                    <div class="col-md-8">
                        <label class="form-label" for="logistic_option">Opção ativa da conta</label>
                        <select class="form-select" id="logistic_option" name="option" required onchange="var p=this.value.split('|'); document.getElementById('shipping_mode').value=p[0]||''; document.getElementById('logistic_type').value=p[1]||'';">
                            <option value="">Selecione…</option>
                            <?php foreach ($options as $option): ?>
                                <?php $value = $option['mode'] . '|' . $option['type']; $selected = ($currentMode === $option['mode'] && $currentType === $option['type']); ?>
                                <option value="<?= esc($value, 'attr') ?>" <?= $selected ? 'selected' : '' ?>>
                                    <?= esc($option['mode'] . ' / ' . $option['type']) ?><?= ! empty($option['default']) ? ' (default)' : '' ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                        <input type="hidden" name="shipping_mode" id="shipping_mode" value="<?= esc($currentMode, 'attr') ?>">
                        <input type="hidden" name="logistic_type" id="logistic_type" value="<?= esc($currentType, 'attr') ?>">
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100">Confirmar e recalcular</button>
                    </div>
                </form>
                <div class="form-text mt-2">Salva em configurações do painel e recalcula as análises. Não publica anúncios.</div>
            <?php endif ?>
        </div>
    </div>
<?php endif ?>

<p class="mb-0"><a href="<?= site_url('profitability') ?>">Voltar à rentabilidade</a></p>
<?= $this->endSection() ?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<form action="<?= site_url('settings') ?>" method="post" novalidate>
    <?= csrf_field() ?>

    <div class="row g-3">
        <?php foreach ($groups as $groupKey => $group): ?>
            <?php if ($group['items'] === []) {
                continue;
            } ?>
            <div class="col-12 col-xl-6">
                <div class="card h-100">
                    <div class="card-header bg-white fw-semibold"><?= esc($group['label']) ?></div>
                    <div class="card-body">
                        <?php if ($groupKey === 'ranking'): ?>
                            <p class="small text-body-secondary">Os pesos serão usados pelo motor de seleção de fornecedores (Fase 3). Eles são normalizados pela soma, então não precisam totalizar 100.</p>
                        <?php endif ?>

                        <?php foreach ($group['items'] as $key => $item): ?>
                            <?php
                            $inputId = 'setting-' . str_replace('.', '-', $key);
                            $old     = old('settings');
                            $current = is_array($old) && array_key_exists($key, $old) ? $old[$key] : $item['value'];
                            ?>
                            <?php if ($item['type'] === 'bool'): ?>
                                <div class="form-check form-switch mb-3">
                                    <input type="hidden" name="settings[<?= esc($key, 'attr') ?>]" value="0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="<?= $inputId ?>" name="settings[<?= esc($key, 'attr') ?>]" value="1" <?= ! empty($current) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="<?= $inputId ?>"><?= esc($item['label']) ?></label>
                                </div>
                            <?php else: ?>
                                <div class="mb-3">
                                    <label class="form-label" for="<?= $inputId ?>"><?= esc($item['label']) ?></label>
                                    <input type="text" inputmode="decimal" class="form-control<?= invalid_class($key) ?>" id="<?= $inputId ?>" name="settings[<?= esc($key, 'attr') ?>]"
                                           value="<?= esc($item['type'] === 'decimal' && $current === $item['value'] ? decimal_br($current) : (string) $current, 'attr') ?>">
                                    <?php if (! empty($item['help'])): ?>
                                        <div class="form-text"><?= esc($item['help']) ?></div>
                                    <?php endif ?>
                                    <div class="invalid-feedback"><?= esc(field_error($key) ?? '') ?></div>
                                </div>
                            <?php endif ?>
                        <?php endforeach ?>
                    </div>
                </div>
            </div>
        <?php endforeach ?>
    </div>

    <div class="mt-3">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Salvar configurações</button>
    </div>
</form>
<?= $this->endSection() ?>

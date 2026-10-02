<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$action = $supplier === null ? site_url('suppliers') : site_url('suppliers/' . $supplier['id']);
$value  = static fn (string $field, mixed $default = '') => old($field) ?? ($supplier[$field] ?? $default);
?>
<form action="<?= $action ?>" method="post" novalidate>
    <?= csrf_field() ?>

    <div class="row g-3">
        <div class="col-12 col-xl-8">
            <div class="card">
                <div class="card-header bg-white fw-semibold">Dados gerais</div>
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <label for="name" class="form-label">Nome *</label>
                        <input type="text" class="form-control<?= invalid_class('name') ?>" id="name" name="name" value="<?= esc($value('name'), 'attr') ?>" required maxlength="120">
                        <div class="invalid-feedback"><?= esc(field_error('name') ?? '') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label for="legal_name" class="form-label">Razão social</label>
                        <input type="text" class="form-control<?= invalid_class('legal_name') ?>" id="legal_name" name="legal_name" value="<?= esc($value('legal_name'), 'attr') ?>" maxlength="190">
                        <div class="invalid-feedback"><?= esc(field_error('legal_name') ?? '') ?></div>
                    </div>
                    <div class="col-md-4">
                        <label for="document" class="form-label">CNPJ/CPF</label>
                        <input type="text" class="form-control<?= invalid_class('document') ?>" id="document" name="document" value="<?= esc($value('document'), 'attr') ?>" maxlength="20" inputmode="numeric">
                        <div class="invalid-feedback"><?= esc(field_error('document') ?? '') ?></div>
                    </div>
                    <div class="col-md-4">
                        <label for="email" class="form-label">E-mail</label>
                        <input type="email" class="form-control<?= invalid_class('email') ?>" id="email" name="email" value="<?= esc($value('email'), 'attr') ?>" maxlength="190">
                        <div class="invalid-feedback"><?= esc(field_error('email') ?? '') ?></div>
                    </div>
                    <div class="col-md-4">
                        <label for="phone" class="form-label">Telefone</label>
                        <input type="text" class="form-control<?= invalid_class('phone') ?>" id="phone" name="phone" value="<?= esc($value('phone'), 'attr') ?>" maxlength="30">
                        <div class="invalid-feedback"><?= esc(field_error('phone') ?? '') ?></div>
                    </div>
                    <div class="col-12">
                        <label for="website" class="form-label">Site</label>
                        <input type="url" class="form-control<?= invalid_class('website') ?>" id="website" name="website" value="<?= esc($value('website'), 'attr') ?>" maxlength="255" placeholder="https://">
                        <div class="invalid-feedback"><?= esc(field_error('website') ?? '') ?></div>
                    </div>
                    <div class="col-12">
                        <label for="notes" class="form-label">Observações</label>
                        <textarea class="form-control<?= invalid_class('notes') ?>" id="notes" name="notes" rows="4" maxlength="5000"><?= esc($value('notes')) ?></textarea>
                        <div class="invalid-feedback"><?= esc(field_error('notes') ?? '') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card">
                <div class="card-header bg-white fw-semibold">Integração e operação</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="integration_type" class="form-label">Tipo de integração *</label>
                        <select class="form-select<?= invalid_class('integration_type') ?>" id="integration_type" name="integration_type">
                            <?php foreach ($integrationTypes as $type => $label): ?>
                                <option value="<?= esc($type, 'attr') ?>" <?= $value('integration_type', 'manual') === $type ? 'selected' : '' ?>><?= esc($label) ?></option>
                            <?php endforeach ?>
                        </select>
                        <div class="form-text">API e XML exigem um adapter específico para o fornecedor.</div>
                        <div class="invalid-feedback"><?= esc(field_error('integration_type') ?? '') ?></div>
                    </div>
                    <div class="mb-3">
                        <label for="status" class="form-label">Status *</label>
                        <select class="form-select<?= invalid_class('status') ?>" id="status" name="status">
                            <?php foreach ($statuses as $status => $label): ?>
                                <option value="<?= esc($status, 'attr') ?>" <?= $value('status', 'active') === $status ? 'selected' : '' ?>><?= esc($label) ?></option>
                            <?php endforeach ?>
                        </select>
                        <div class="form-text">Fornecedores bloqueados nunca são selecionados automaticamente.</div>
                        <div class="invalid-feedback"><?= esc(field_error('status') ?? '') ?></div>
                    </div>
                    <div class="mb-3">
                        <label for="priority" class="form-label">Prioridade (0 a 10) *</label>
                        <input type="number" class="form-control<?= invalid_class('priority') ?>" id="priority" name="priority" min="0" max="10" value="<?= esc((string) $value('priority', 5), 'attr') ?>">
                        <div class="form-text">Quanto maior, mais preferido no desempate.</div>
                        <div class="invalid-feedback"><?= esc(field_error('priority') ?? '') ?></div>
                    </div>
                    <div class="mb-3">
                        <label for="default_shipping_days" class="form-label">Prazo padrão de postagem (dias)</label>
                        <input type="number" class="form-control<?= invalid_class('default_shipping_days') ?>" id="default_shipping_days" name="default_shipping_days" min="0" max="365" value="<?= esc((string) $value('default_shipping_days'), 'attr') ?>">
                        <div class="invalid-feedback"><?= esc(field_error('default_shipping_days') ?? '') ?></div>
                    </div>
                    <div class="mb-3">
                        <label for="safety_stock" class="form-label">Estoque de segurança (unidades)</label>
                        <input type="number" class="form-control<?= invalid_class('safety_stock') ?>" id="safety_stock" name="safety_stock" min="0" max="65535" value="<?= esc((string) $value('safety_stock'), 'attr') ?>">
                        <div class="form-text">Vazio = usa o valor global. O valor definido no produto prevalece sobre este.</div>
                        <div class="invalid-feedback"><?= esc(field_error('safety_stock') ?? '') ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Salvar</button>
        <a href="<?= site_url($supplier === null ? 'suppliers' : 'suppliers/' . $supplier['id']) ?>" class="btn btn-light">Cancelar</a>
    </div>
</form>
<?= $this->endSection() ?>

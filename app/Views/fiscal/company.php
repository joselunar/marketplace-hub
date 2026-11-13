<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php
$c = $company ?? [];
$tax = $tax ?? [];
$certificate = $certificate ?? null;
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h2 class="h5 mb-1">Empresa fiscal</h2>
        <div class="small text-body-secondary">Cadastro para emissão NF-e modelo 55. Não inventamos CFOP/CSOSN/CRT.</div>
    </div>
    <a class="btn btn-sm btn-light" href="<?= site_url('fiscal') ?>">Voltar ao painel</a>
</div>

<div class="alert alert-warning small">
    Emissão real permanece desligada. Transmissões usam homologação SEFAZ até autorização explícita em Configurações → Fiscal.
    Regras de dropshipping / venda à ordem devem ser validadas pelo contador antes de marcar os parâmetros tributários.
</div>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold">Identificação e endereço</div>
    <div class="card-body">
        <form method="post" action="<?= site_url('fiscal/company') ?>" class="row g-3">
            <?= csrf_field() ?>
            <div class="col-md-6">
                <label class="form-label">Razão social</label>
                <input name="legal_name" class="form-control" required value="<?= esc(old('legal_name', $c['legal_name'] ?? ''), 'attr') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Nome fantasia</label>
                <input name="trade_name" class="form-control" value="<?= esc(old('trade_name', $c['trade_name'] ?? ''), 'attr') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">CNPJ/CPF</label>
                <input name="document" class="form-control" required value="<?= esc(old('document', $c['document'] ?? ''), 'attr') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">IE</label>
                <input name="state_registration" class="form-control" value="<?= esc(old('state_registration', $c['state_registration'] ?? ''), 'attr') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">CRT (contador)</label>
                <input name="crt" type="number" min="1" max="4" class="form-control" value="<?= esc(old('crt', $c['crt'] ?? ''), 'attr') ?>" placeholder="Ex.: 1 Simples">
            </div>
            <div class="col-md-3">
                <label class="form-label">CEP</label>
                <input name="zip_code" class="form-control" value="<?= esc(old('zip_code', $c['zip_code'] ?? ''), 'attr') ?>">
            </div>
            <div class="col-md-7">
                <label class="form-label">Logradouro</label>
                <input name="street" class="form-control" value="<?= esc(old('street', $c['street'] ?? ''), 'attr') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Número</label>
                <input name="number" class="form-control" value="<?= esc(old('number', $c['number'] ?? ''), 'attr') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Bairro</label>
                <input name="district" class="form-control" value="<?= esc(old('district', $c['district'] ?? ''), 'attr') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Cidade</label>
                <input name="city" class="form-control" value="<?= esc(old('city', $c['city'] ?? ''), 'attr') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">UF</label>
                <input name="uf" maxlength="2" class="form-control" value="<?= esc(old('uf', $c['uf'] ?? 'RN'), 'attr') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">IBGE município</label>
                <input name="city_ibge" class="form-control" value="<?= esc(old('city_ibge', $c['city_ibge'] ?? ''), 'attr') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">E-mail</label>
                <input name="email" type="email" class="form-control" value="<?= esc(old('email', $c['email'] ?? ''), 'attr') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Telefone</label>
                <input name="phone" class="form-control" value="<?= esc(old('phone', $c['phone'] ?? ''), 'attr') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Série produção</label>
                <input name="serie_nfe" type="number" min="1" class="form-control" value="<?= esc(old('serie_nfe', $c['serie_nfe'] ?? 1), 'attr') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Próx. nº produção</label>
                <input name="next_number_nfe" type="number" min="1" class="form-control" value="<?= esc(old('next_number_nfe', $c['next_number_nfe'] ?? 1), 'attr') ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Observação de regime</label>
                <input name="tax_regime_note" class="form-control" value="<?= esc(old('tax_regime_note', $c['tax_regime_note'] ?? ''), 'attr') ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit">Salvar empresa</button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold">Parâmetros tributários (contador)</div>
    <div class="card-body">
        <?php if (empty($c['id'])): ?>
            <p class="text-body-secondary mb-0">Salve a empresa antes de informar parâmetros tributários.</p>
        <?php else: ?>
            <form method="post" action="<?= site_url('fiscal/company/tax') ?>" class="row g-3">
                <?= csrf_field() ?>
                <div class="col-md-3">
                    <label class="form-label">CFOP</label>
                    <input name="tax[cfop]" class="form-control" value="<?= esc($tax['cfop'] ?? '', 'attr') ?>" placeholder="Informado pelo contador">
                </div>
                <div class="col-md-3">
                    <label class="form-label">CSOSN</label>
                    <input name="tax[csosn]" class="form-control" value="<?= esc($tax['csosn'] ?? '', 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">NCM padrão</label>
                    <input name="tax[ncm_default]" class="form-control" value="<?= esc($tax['ncm_default'] ?? '', 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Origem mercadoria</label>
                    <input name="tax[origem]" class="form-control" value="<?= esc($tax['origem'] ?? '0', 'attr') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Natureza da operação</label>
                    <input name="tax[natureza_operacao]" class="form-control" value="<?= esc($tax['natureza_operacao'] ?? '', 'attr') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Informações complementares</label>
                    <input name="tax[informacoes_complementares]" class="form-control" value="<?= esc($tax['informacoes_complementares'] ?? '', 'attr') ?>">
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="taxValidated" name="tax_params_validated" <?= ! empty($c['tax_params_validated']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="taxValidated">
                            Parâmetros validados pelo contador (obrigatório para transmitir)
                        </label>
                    </div>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Salvar parâmetros</button>
                </div>
            </form>
        <?php endif ?>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold">Certificado A1</div>
    <div class="card-body">
        <?php if ($certificate): ?>
            <p class="small mb-3">
                Ativo: <?= esc($certificate['label'] ?? 'A1') ?>
                · <?= esc($certificate['subject'] ?? 'sem assunto') ?>
                <?php if (! empty($certificate['valid_to'])): ?>
                    · válido até <?= esc($certificate['valid_to']) ?>
                <?php endif ?>
            </p>
        <?php else: ?>
            <p class="small text-body-secondary">Nenhum certificado ativo. Sem A1 não há comunicação com a SEFAZ.</p>
        <?php endif ?>
        <?php if (empty($c['id'])): ?>
            <p class="text-body-secondary mb-0">Salve a empresa antes de enviar o certificado.</p>
        <?php else: ?>
            <form method="post" action="<?= site_url('fiscal/certificate') ?>" enctype="multipart/form-data" class="row g-3">
                <?= csrf_field() ?>
                <div class="col-md-4">
                    <label class="form-label">Arquivo .pfx / .p12</label>
                    <input type="file" name="certificate" class="form-control" accept=".pfx,.p12" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Senha do certificado</label>
                    <input type="password" name="password" class="form-control" required autocomplete="new-password">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Rótulo</label>
                    <input name="label" class="form-control" value="A1">
                </div>
                <div class="col-12">
                    <button class="btn btn-outline-primary" type="submit">Armazenar certificado</button>
                    <div class="form-text">Arquivo fora do webroot; senha cifrada. Nunca exibida depois do envio.</div>
                </div>
            </form>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

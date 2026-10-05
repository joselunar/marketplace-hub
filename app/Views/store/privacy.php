<?php /** @var \Config\Store $store */ ?>
<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<section class="store-section">
    <div class="container store-policy">
        <h1>Política de privacidade</h1>
        <p class="lead">Esta política explica como a <?= esc($store->name) ?> (<?= esc($store->legalName) ?>, CNPJ <?= esc($store->cnpj) ?>) trata dados pessoais, de acordo com a Lei Geral de Proteção de Dados (Lei 13.709/2018).</p>

        <h2>Quais dados recebemos</h2>
        <p>As compras acontecem no Mercado Livre. Para entregar o seu pedido, recebemos da plataforma apenas o necessário: nome, endereço de entrega, itens comprados e mensagens trocadas sobre o pedido. <strong>Não temos acesso aos dados do seu cartão ou conta bancária</strong>, que ficam somente com o Mercado Pago.</p>
        <p>Se você falar com a gente por WhatsApp ou e-mail, guardamos as mensagens e o contato informado para responder ao seu atendimento.</p>

        <h2>Para que usamos</h2>
        <ul>
            <li>Separar, enviar e acompanhar o seu pedido.</li>
            <li>Responder dúvidas, trocas, devoluções e solicitações de atendimento.</li>
            <li>Cumprir obrigações legais e fiscais.</li>
        </ul>
        <p>Não vendemos nem compartilhamos seus dados para publicidade. Os dados são compartilhados apenas com quem participa da entrega do pedido (Mercado Livre, transportadoras e fornecedores responsáveis pelo envio).</p>

        <h2>Este site</h2>
        <p>Este site é apenas informativo: não tem cadastro, não coleta formulários e não usa cookies de rastreamento ou publicidade.</p>

        <h2>Seus direitos</h2>
        <p>Você pode pedir a qualquer momento a confirmação, correção ou exclusão dos seus dados que estejam com a gente, pelo e-mail <a href="mailto:<?= esc($store->email, 'attr') ?>"><?= esc($store->email) ?></a>. Dados tratados pelo Mercado Livre seguem a política de privacidade da própria plataforma.</p>

        <p class="small text-body-secondary mt-4">Última atualização: outubro de 2026.</p>
    </div>
</section>
<?= $this->endSection() ?>

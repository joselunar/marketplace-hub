<?php /** @var \Config\Store $store */ ?>
<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<section class="store-section">
    <div class="container store-policy">
        <h1>Trocas e devoluções</h1>
        <p class="lead">Todas as compras da <?= esc($store->name) ?> são feitas pelo Mercado Livre, e as trocas e devoluções seguem as regras da plataforma e o Código de Defesa do Consumidor.</p>

        <h2>Direito de arrependimento</h2>
        <p>Você pode desistir da compra em até <strong>7 dias corridos após o recebimento</strong>, sem precisar justificar (art. 49 do Código de Defesa do Consumidor). O produto deve ser devolvido com seus acessórios e, sempre que possível, na embalagem original.</p>

        <h2>Produto com defeito ou diferente do anúncio</h2>
        <p>Se o produto chegou com defeito, avariado ou diferente do que foi anunciado, abra uma reclamação em <strong>Minhas compras</strong> no Mercado Livre. Com a Compra Garantida, você recebe o produto correto ou o seu dinheiro de volta. Para defeitos que aparecerem depois, vale a garantia legal de 90 dias para produtos duráveis e 30 dias para não duráveis.</p>

        <h2>Como solicitar</h2>
        <ol>
            <li>Acesse <strong>Minhas compras</strong> no app ou site do Mercado Livre.</li>
            <li>Selecione o pedido e escolha <strong>Devolver produto</strong> ou <strong>Tive um problema</strong>.</li>
            <li>Siga as instruções para gerar a etiqueta de devolução, quando disponível.</li>
            <li>Após o recebimento e conferência, o reembolso é feito pelo Mercado Pago no mesmo meio de pagamento.</li>
        </ol>

        <h2>Precisa de ajuda?</h2>
        <p>Se tiver qualquer dificuldade, fale com a gente antes de abrir a reclamação. Resolvemos rápido:</p>
        <ul>
            <li>WhatsApp: <a href="<?= esc($store->whatsappLink(), 'attr') ?>" target="_blank" rel="noopener"><?= esc($store->whatsappDisplay()) ?></a></li>
            <li>E-mail: <a href="mailto:<?= esc($store->email, 'attr') ?>"><?= esc($store->email) ?></a></li>
            <li>Horário: <?= esc($store->hours) ?></li>
        </ul>

        <p class="small text-body-secondary mt-4"><?= esc($store->legalName) ?> · CNPJ <?= esc($store->cnpj) ?> · <?= esc($store->city) ?></p>
    </div>
</section>
<?= $this->endSection() ?>

<?php /** @var \Config\Store $store */ ?>
<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<section class="store-hero">
    <div class="container">
        <div class="row">
            <div class="col-lg-6 col-xl-5">
                <span class="store-eyebrow"><i class="bi bi-patch-check-fill me-1"></i> Empresa formalizada · CNPJ <?= esc($store->cnpj) ?></span>
                <h1 class="store-hero-title">Variedades para sua casa e sua rotina, com a segurança do Mercado Livre</h1>
                <p class="store-hero-text">Produtos selecionados para casa, tecnologia, ferramentas, beleza, pet e esportes. Você compra e paga dentro do Mercado Livre, com a proteção da Compra Garantida.</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-store-primary btn-lg" href="<?= esc($store->shopUrl(), 'attr') ?>" target="_blank" rel="noopener">
                        <i class="bi bi-bag-check me-1"></i> Ver produtos no Mercado Livre
                    </a>
                    <a class="btn btn-outline-dark btn-lg" href="<?= esc($store->whatsappLink(), 'attr') ?>" target="_blank" rel="noopener">
                        <i class="bi bi-whatsapp me-1"></i> Falar no WhatsApp
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="store-trust">
    <div class="container">
        <div class="row g-3">
            <div class="col-6 col-lg-3">
                <div class="store-trust-item">
                    <i class="bi bi-shield-check"></i>
                    <div>
                        <strong>Compra Garantida</strong>
                        <span>Se o produto não chegar ou vier diferente do anúncio, o Mercado Livre devolve seu dinheiro.</span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="store-trust-item">
                    <i class="bi bi-credit-card-2-front"></i>
                    <div>
                        <strong>Pagamento pelo Mercado Pago</strong>
                        <span>Seus dados de pagamento ficam com o Mercado Pago, nunca com a loja.</span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="store-trust-item">
                    <i class="bi bi-truck"></i>
                    <div>
                        <strong>Envio rastreado</strong>
                        <span>Acompanhe cada etapa da entrega direto no app do Mercado Livre.</span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="store-trust-item">
                    <i class="bi bi-chat-dots"></i>
                    <div>
                        <strong>Atendimento de verdade</strong>
                        <span>Fale com a gente por WhatsApp, e-mail ou pelo chat da compra.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="store-section" id="categorias">
    <div class="container">
        <div class="store-section-head">
            <h2>Categorias</h2>
            <p>Uma seleção de produtos úteis, escolhidos para durar e chegar bem até você.</p>
        </div>
        <div class="row g-3 g-lg-4">
            <?php foreach ($categories as $category): ?>
                <div class="col-6 col-lg-4">
                    <a class="store-category" href="<?= esc($store->shopUrl(), 'attr') ?>" target="_blank" rel="noopener">
                        <img src="<?= base_url('assets/store/categoria-' . $category['slug'] . '.jpg') ?>" alt="<?= esc($category['name'], 'attr') ?>" width="600" height="600" loading="lazy">
                        <div class="store-category-body">
                            <h3><?= esc($category['name']) ?></h3>
                            <p><?= esc($category['text']) ?></p>
                        </div>
                    </a>
                </div>
            <?php endforeach ?>
        </div>
    </div>
</section>

<section class="store-section store-section-alt" id="como-comprar">
    <div class="container">
        <div class="store-section-head">
            <h2>Como comprar</h2>
            <p>Toda a compra acontece dentro do Mercado Livre. Simples e protegido.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="store-step">
                    <span class="store-step-number">1</span>
                    <h3>Escolha o produto</h3>
                    <p>Acesse nossa loja no Mercado Livre e veja fotos, descrição, prazo de entrega e perguntas de outros compradores.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="store-step">
                    <span class="store-step-number">2</span>
                    <h3>Pague com segurança</h3>
                    <p>Pix, cartão ou boleto pelo Mercado Pago. O valor só é liberado para a loja depois que você recebe o pedido.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="store-step">
                    <span class="store-step-number">3</span>
                    <h3>Receba e acompanhe</h3>
                    <p>Você recebe o código de rastreio e acompanha a entrega pelo app. Qualquer dúvida, é só chamar.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="store-section">
    <div class="container">
        <div class="row g-4 g-lg-5 align-items-center">
            <div class="col-lg-6">
                <img class="store-feature-img" src="<?= base_url('assets/store/envio-embalagem.jpg') ?>" alt="Pedidos embalados e prontos para envio" width="1000" height="750" loading="lazy">
            </div>
            <div class="col-lg-6">
                <h2 class="store-feature-title">Cuidado do pedido até a sua porta</h2>
                <ul class="store-checklist">
                    <li><i class="bi bi-check-circle-fill"></i> Produtos conferidos antes do envio.</li>
                    <li><i class="bi bi-check-circle-fill"></i> Embalagem reforçada para chegar sem avarias.</li>
                    <li><i class="bi bi-check-circle-fill"></i> Prazo de entrega informado em cada anúncio, antes da compra.</li>
                    <li><i class="bi bi-check-circle-fill"></i> Troca ou devolução pelo próprio Mercado Livre se algo der errado.</li>
                </ul>
                <a class="btn btn-link px-0" href="<?= site_url('trocas-e-devolucoes') ?>">Ver política de trocas e devoluções <i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
    </div>
</section>

<section class="store-section store-section-alt" id="duvidas">
    <div class="container">
        <div class="store-section-head">
            <h2>Dúvidas frequentes</h2>
        </div>
        <div class="accordion store-faq mx-auto" id="faq">
            <?php
            $faq = [
                ['É seguro comprar da Vitrine Hub?', 'Sim. Todas as vendas são feitas dentro do Mercado Livre e pagas pelo Mercado Pago. Com a Compra Garantida, se o produto não chegar ou for diferente do anunciado, o Mercado Livre devolve o seu dinheiro. Além disso, somos uma empresa formalizada, com CNPJ ' . $store->cnpj . '.'],
                ['Quanto tempo demora a entrega?', 'O prazo aparece em cada anúncio antes de você finalizar a compra, calculado para o seu CEP. Depois do envio, você acompanha tudo pelo código de rastreio no app do Mercado Livre.'],
                ['Posso trocar ou devolver um produto?', 'Sim. Você pode desistir da compra em até 7 dias após o recebimento, conforme o Código de Defesa do Consumidor, e solicitar a devolução direto em "Minhas compras" no Mercado Livre. Veja os detalhes na nossa política de trocas e devoluções.'],
                ['Vocês emitem nota fiscal?', 'Somos uma empresa formalizada (MEI). Se precisar da nota fiscal da sua compra, fale com a gente pelo chat da compra no Mercado Livre ou pelos nossos canais de atendimento.'],
                ['Como falo com a loja?', 'Pelo WhatsApp ' . $store->whatsappDisplay() . ', pelo e-mail ' . $store->email . ' ou pelo chat da compra no Mercado Livre. Atendemos ' . mb_strtolower($store->hours) . '.'],
            ];
            ?>
            <?php foreach ($faq as $i => [$question, $answer]): ?>
                <div class="accordion-item">
                    <h3 class="accordion-header">
                        <button class="accordion-button <?= $i === 0 ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= $i ?>" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>" aria-controls="faq<?= $i ?>">
                            <?= esc($question) ?>
                        </button>
                    </h3>
                    <div id="faq<?= $i ?>" class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>" data-bs-parent="#faq">
                        <div class="accordion-body"><?= esc($answer) ?></div>
                    </div>
                </div>
            <?php endforeach ?>
        </div>
    </div>
</section>

<section class="store-section" id="contato">
    <div class="container">
        <div class="row g-4 g-lg-5 align-items-center">
            <div class="col-lg-6 order-lg-2">
                <img class="store-feature-img" src="<?= base_url('assets/store/atendimento.jpg') ?>" alt="Atendimento da Vitrine Hub" width="1000" height="750" loading="lazy">
            </div>
            <div class="col-lg-6 order-lg-1">
                <h2 class="store-feature-title">Fale com a gente</h2>
                <p class="text-body-secondary">Tem dúvida sobre um produto, prazo ou pedido? Respondemos pessoalmente, sem robô.</p>
                <div class="d-grid gap-3 store-contact">
                    <a class="store-contact-item" href="<?= esc($store->whatsappLink(), 'attr') ?>" target="_blank" rel="noopener">
                        <i class="bi bi-whatsapp"></i>
                        <span><strong>WhatsApp</strong><?= esc($store->whatsappDisplay()) ?></span>
                    </a>
                    <a class="store-contact-item" href="mailto:<?= esc($store->email, 'attr') ?>">
                        <i class="bi bi-envelope"></i>
                        <span><strong>E-mail</strong><?= esc($store->email) ?></span>
                    </a>
                    <div class="store-contact-item">
                        <i class="bi bi-clock"></i>
                        <span><strong>Horário de atendimento</strong><?= esc($store->hours) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

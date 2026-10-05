<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Dados públicos da loja exibidos no site institucional.
 *
 * Podem ser sobrescritos no .env (STORE_EMAIL, STORE_WHATSAPP,
 * STORE_MELI_URL, STORE_HOURS etc.).
 */
class Store extends BaseConfig
{
    public string $name      = 'Vitrine Hub';
    public string $legalName = 'JOSE VICTOR DA SILVA VIANA';
    public string $cnpj      = '69.461.433/0001-40';
    public string $city      = 'Mossoró/RN';
    public string $email     = 'contatovitrinehub@gmail.com';

    /**
     * Somente dígitos, com DDI e DDD (formato do wa.me).
     */
    public string $whatsapp = '5584996251471';

    public string $hours = 'Segunda a sexta, das 9h às 18h';

    /**
     * Página da loja no Mercado Livre. Vazio enquanto a conta não estiver
     * pronta: os botões passam a apontar para o WhatsApp.
     */
    public string $mercadoLivreUrl = '';

    public function __construct()
    {
        parent::__construct();

        $this->email           = trim((string) env('STORE_EMAIL', $this->email));
        $this->whatsapp        = preg_replace('/\D+/', '', (string) env('STORE_WHATSAPP', $this->whatsapp));
        $this->hours           = trim((string) env('STORE_HOURS', $this->hours));
        $this->mercadoLivreUrl = trim((string) env('STORE_MELI_URL', $this->mercadoLivreUrl));
    }

    public function whatsappDisplay(): string
    {
        $local = substr($this->whatsapp, 2);

        return sprintf('(%s) %s-%s', substr($local, 0, 2), substr($local, 2, 5), substr($local, 7));
    }

    public function whatsappLink(string $message = 'Olá! Vim pelo site da Vitrine Hub.'): string
    {
        return 'https://wa.me/' . $this->whatsapp . '?text=' . rawurlencode($message);
    }

    /**
     * Destino dos botões de compra.
     */
    public function shopUrl(): string
    {
        return $this->mercadoLivreUrl !== '' ? $this->mercadoLivreUrl : $this->whatsappLink('Olá! Quero conhecer os produtos da Vitrine Hub.');
    }
}

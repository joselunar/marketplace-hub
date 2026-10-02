<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Integração com o Mercado Livre.
 *
 * Credenciais vêm somente do .env (MELI_CLIENT_ID, MELI_CLIENT_SECRET,
 * MELI_REDIRECT_URI). URLs seguem a documentação oficial de autenticação:
 * https://developers.mercadolivre.com.br/pt_br/autenticacao-e-autorizacao
 * e podem ser trocadas por mercadolivre.authUrl / mercadolivre.apiUrl no .env
 * (ex.: domínio de autorização de outro país).
 */
class MercadoLivre extends BaseConfig
{
    public string $clientId     = '';
    public string $clientSecret = '';
    public string $redirectUri  = '';

    public string $authUrl = 'https://auth.mercadolivre.com.br/authorization';
    public string $apiUrl  = 'https://api.mercadolibre.com';

    /**
     * Site usado quando a conta ainda não informou o próprio (MLB = Brasil).
     */
    public string $siteId = 'MLB';

    /**
     * PKCE (S256). Se o aplicativo tiver PKCE habilitado no painel do
     * Mercado Livre, o envio é obrigatório; desligue com MELI_USE_PKCE=false
     * apenas se a troca do code falhar por causa dele.
     */
    public bool $usePkce = true;

    /**
     * O access token vale 6 horas. Ele é renovado quando faltar menos que isto.
     */
    public int $refreshBeforeSeconds = 1800;

    /**
     * Validade do state/code_verifier guardados na sessão durante a autorização.
     */
    public int $stateTtlSeconds = 600;

    public int $timeoutSeconds = 20;

    /**
     * Árvore de categorias e atributos mudam pouco; evita consultas repetidas.
     */
    public int $categoryCacheSeconds = 21600;

    /**
     * Tarifas mudam com promoções e faixas de preço: cache curto.
     */
    public int $feeCacheSeconds = 600;

    /**
     * Perfil do vendedor (tags como user_product_seller).
     */
    public int $sellerCacheSeconds = 3600;

    /**
     * Tipo de logística e modo de envio do vendedor, enviados na consulta de
     * tarifas (listing_prices). Sem eles o Mercado Livre devolve uma tarifa
     * fixa que pode não coincidir com a cobrada. Ex.: drop_off / me2.
     */
    public string $logisticType = '';
    public string $shippingMode = '';

    public function __construct()
    {
        parent::__construct();

        $this->clientId     = trim((string) env('MELI_CLIENT_ID', $this->clientId));
        $this->clientSecret = trim((string) env('MELI_CLIENT_SECRET', $this->clientSecret));
        $this->redirectUri  = trim((string) env('MELI_REDIRECT_URI', $this->redirectUri));
        $this->logisticType = trim((string) env('MELI_LOGISTIC_TYPE', $this->logisticType));
        $this->shippingMode = trim((string) env('MELI_SHIPPING_MODE', $this->shippingMode));

        $pkce = env('MELI_USE_PKCE');
        if ($pkce !== null && $pkce !== '') {
            $this->usePkce = filter_var($pkce, FILTER_VALIDATE_BOOLEAN);
        }
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->redirectUri !== '';
    }

    /**
     * Itens que faltam no .env, para orientar quem está configurando.
     *
     * @return list<string>
     */
    public function missingSettings(): array
    {
        return array_keys(array_filter([
            'MELI_CLIENT_ID'     => $this->clientId === '',
            'MELI_CLIENT_SECRET' => $this->clientSecret === '',
            'MELI_REDIRECT_URI'  => $this->redirectUri === '',
        ]));
    }
}

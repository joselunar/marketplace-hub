<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\SyncLogModel;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreAuthService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreService;
use App\Services\Security\TokenCipher;
use CodeIgniter\HTTP\RedirectResponse;

class MercadoLivreController extends BaseController
{
    private const HOME = 'marketplaces/mercadolivre';

    private MercadoLivreService $meli;

    public function __construct()
    {
        $this->meli = service('mercadoLivre');
    }

    public function index(): string
    {
        $config = $this->meli->config();

        return view('mercadolivre/index', [
            'title'       => 'Mercado Livre',
            'tab'         => 'accounts',
            'accounts'    => $this->meli->accounts()->listFor(MercadoLivreAuthService::SLUG),
            'configured'  => $config->isConfigured(),
            'missing'     => $config->missingSettings(),
            'integration' => [
                'client_id'         => $config->clientId !== '' ? TokenCipher::mask($config->clientId) : null,
                'redirect_uri'      => $config->redirectUri,
                'expected_redirect' => site_url(self::HOME . '/callback'),
                'pkce'              => $config->usePkce,
                'auth_host'         => parse_url($config->authUrl, PHP_URL_HOST),
                'site_id'           => $config->siteId,
            ],
            'recentLogs'  => (new SyncLogModel())
                ->where('integration_type', MercadoLivreAuthService::SYNC_INTEGRATION)
                ->orderBy('id', 'DESC')
                ->findAll(8),
            'breadcrumbs' => ['Marketplaces' => null, 'Mercado Livre' => null],
        ]);
    }

    /**
     * Envia o usuário para a tela de autorização do Mercado Livre.
     */
    public function connect(): RedirectResponse
    {
        try {
            $url = $this->meli->auth()->authorizationUrl();
        } catch (BusinessRuleException $e) {
            return redirect()->to(self::HOME)->with('error', $e->getMessage());
        }

        return redirect()->to($url);
    }

    /**
     * redirect_uri cadastrada no aplicativo do Mercado Livre.
     */
    public function callback(): RedirectResponse
    {
        try {
            $result = $this->meli->auth()->handleCallback($this->request->getGet() ?? [], $this->auth()->id());
        } catch (BusinessRuleException $e) {
            return redirect()->to(self::HOME)->with('error', $e->getMessage());
        }

        $message = $result['created'] ? 'Conta %s conectada ao Mercado Livre.' : 'Conta %s reconectada; tokens atualizados.';

        return redirect()->to(self::HOME)->with('success', sprintf($message, $result['name']));
    }

    public function refresh(int $id): RedirectResponse
    {
        try {
            $this->meli->resolveAccount($id);
            $account = $this->meli->auth()->refresh($id, true);
        } catch (BusinessRuleException $e) {
            return redirect()->to(self::HOME)->with('error', $e->getMessage());
        }

        return redirect()->to(self::HOME)->with('success', 'Token renovado. Novo vencimento: ' . format_datetime($account['token_expires_at']) . '.');
    }

    public function disconnect(int $id): RedirectResponse
    {
        try {
            $account = $this->meli->resolveAccount($id);
            $revoked = $this->meli->auth()->disconnect($id);
        } catch (BusinessRuleException $e) {
            return redirect()->to(self::HOME)->with('error', $e->getMessage());
        }

        return $revoked
            ? redirect()->to(self::HOME)->with('success', 'Conta ' . $account['name'] . ' desconectada e permissão revogada no Mercado Livre.')
            : redirect()->to(self::HOME)->with('warning', 'Conta ' . $account['name'] . ' desconectada neste painel e tokens apagados. Não foi possível confirmar a revogação no Mercado Livre; se quiser, remova a permissão do aplicativo pela sua conta do Mercado Livre.');
    }
}

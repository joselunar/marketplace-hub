<?php

namespace App\Controllers;

use App\Services\Auth\AuthService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Base dos controllers do painel.
 *
 * Controllers ficam finos: leem a requisição, chamam um service e
 * escolhem a resposta. Regras de negócio ficam nos services.
 */
abstract class BaseController extends Controller
{
    protected const PER_PAGE_OPTIONS = [20, 50, 100];

    /**
     * @var IncomingRequest
     */
    protected $request;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
    }

    protected function auth(): AuthService
    {
        return service('auth');
    }

    /**
     * Volta ao formulário com erros e dados digitados, sem guardar senhas na sessão.
     *
     * @param array<string, string> $errors
     */
    protected function backWithErrors(array $errors, string $message = 'Verifique os dados informados.'): RedirectResponse
    {
        $post = $this->request->getPost() ?? [];
        unset($post['password'], $post['password_confirm'], $post[csrf_token()]);

        // Mesmo formato usado por RedirectResponse::withInput(), lido pelo helper old().
        return redirect()->back()
            ->with('_ci_old_input', ['get' => [], 'post' => $post])
            ->with('errors', $errors)
            ->with('error', $message);
    }

    /**
     * Lê filtros de listagem da query string, já com trim.
     *
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    protected function filters(array $keys): array
    {
        $filters = [];

        foreach ($keys as $key) {
            $value = $this->request->getGet($key);

            if (is_string($value) && trim($value) !== '') {
                $filters[$key] = mb_substr(trim($value), 0, 100);
            }
        }

        return $filters;
    }

    protected function perPage(): int
    {
        $perPage = (int) $this->request->getGet('per_page');

        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::PER_PAGE_OPTIONS[0];
    }
}

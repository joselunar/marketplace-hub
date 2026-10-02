<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Exige usuário autenticado. Rotas /api recebem 401 em JSON;
 * as demais são redirecionadas para o login.
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (service('auth')->check()) {
            return null;
        }

        if (str_starts_with(trim($request->getUri()->getPath(), '/'), 'api')) {
            return service('response')
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['error' => 'Não autenticado.']);
        }

        if ($request->getMethod() === 'GET') {
            session()->set('redirect_after_login', current_url(true)->getPath());
        }

        return redirect()->to('/login')->with('warning', 'Faça login para continuar.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

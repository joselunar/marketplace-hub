<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Restringe a rota aos papéis informados. Uso: 'role:admin' ou 'role:admin,operator'.
 */
class RoleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $auth = service('auth');

        if ($auth->hasRole(...($arguments ?? []))) {
            return null;
        }

        if (str_starts_with(trim($request->getUri()->getPath(), '/'), 'api')) {
            return service('response')
                ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                ->setJSON(['error' => 'Acesso negado.']);
        }

        return service('response')
            ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
            ->setBody(view('errors/forbidden', ['user' => $auth->user()]));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

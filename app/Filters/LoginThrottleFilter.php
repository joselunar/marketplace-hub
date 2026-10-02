<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Limita tentativas de login por IP (5 por minuto).
 */
class LoginThrottleFilter implements FilterInterface
{
    private const MAX_ATTEMPTS = 5;

    public function before(RequestInterface $request, $arguments = null)
    {
        if ($request->getMethod() !== 'POST') {
            return null;
        }

        $key = 'login_' . md5($request->getIPAddress());

        if (service('throttler')->check($key, self::MAX_ATTEMPTS, MINUTE) === false) {
            return redirect()->to('/login')
                ->with('error', 'Muitas tentativas de login. Aguarde um minuto e tente novamente.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

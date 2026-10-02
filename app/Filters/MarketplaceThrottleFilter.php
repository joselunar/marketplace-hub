<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Limita as ações que escrevem no marketplace (publicar, preço, estoque,
 * status) a 30 por minuto por usuário, para não estourar a cota da API.
 */
class MarketplaceThrottleFilter implements FilterInterface
{
    private const MAX_ACTIONS = 30;

    public function before(RequestInterface $request, $arguments = null)
    {
        if ($request->getMethod() !== 'POST') {
            return null;
        }

        $who = (string) (session('user_id') ?? $request->getIPAddress());

        if (service('throttler')->check('marketplace_' . md5($who), self::MAX_ACTIONS, MINUTE) === false) {
            return redirect()->back()->with('error', 'Muitas operações seguidas no marketplace. Aguarde um minuto e tente novamente.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

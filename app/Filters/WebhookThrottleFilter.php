<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Limite por IP no webhook público, contra inundação de requisições.
 * Folgado o bastante para os picos normais de notificações; um 429
 * faz o Mercado Livre reenviar depois.
 */
class WebhookThrottleFilter implements FilterInterface
{
    private const MAX_REQUESTS = 600;

    public function before(RequestInterface $request, $arguments = null)
    {
        if (service('throttler')->check('webhook_' . md5($request->getIPAddress()), self::MAX_REQUESTS, MINUTE) === false) {
            return service('response')->setStatusCode(429)->setJSON(['message' => 'Muitas requisições.']);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

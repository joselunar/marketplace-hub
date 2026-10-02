<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\MercadoLivre;

/**
 * Endpoints públicos chamados pelos marketplaces (sem sessão e sem CSRF;
 * a validação é feita aqui e no service).
 */
class WebhookController extends Controller
{
    /**
     * @var IncomingRequest
     */
    protected $request;

    public function mercadolivre(): ResponseInterface
    {
        $config = config(MercadoLivre::class);

        if ($config->webhookAllowedIps !== [] && ! in_array($this->request->getIPAddress(), $config->webhookAllowedIps, true)) {
            return $this->reply(403, 'Origem não autorizada.');
        }

        $body = (string) $this->request->getBody();
        if (strlen($body) > $config->webhookMaxBodyBytes) {
            return $this->reply(413, 'Notificação grande demais.');
        }

        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->reply(400, 'Corpo inválido (JSON esperado).');
        }

        if (! is_array($payload)) {
            return $this->reply(400, 'Corpo inválido (JSON esperado).');
        }

        $result = service('mercadoLivreNotifications')->receive($payload);

        return $this->reply($result['status'], $result['message']);
    }

    private function reply(int $status, string $message): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON(['message' => $message]);
    }
}

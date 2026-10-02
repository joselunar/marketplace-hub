<?php

namespace App\Services\Marketplaces\MercadoLivre\Http;

use CodeIgniter\HTTP\Exceptions\HTTPException;
use Config\Services;

class CurlTransport implements HttpTransport
{
    public function __construct(private readonly int $timeoutSeconds = 20)
    {
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        // Instância nova a cada chamada: o CURLRequest compartilhado acumula opções entre requisições.
        $client = Services::curlrequest([
            'timeout'         => $this->timeoutSeconds,
            'connect_timeout' => 10,
            'http_errors'     => false,
            'verify'          => true,
        ], null, null, false);

        $options = ['headers' => $headers];
        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $response = $client->request($method, $url, $options);
        } catch (HTTPException $e) {
            throw new TransportException('Sem resposta do Mercado Livre: ' . $e->getMessage(), 0, $e);
        }

        return new HttpResponse($response->getStatusCode(), (string) $response->getBody());
    }
}

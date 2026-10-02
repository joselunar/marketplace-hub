<?php

namespace App\Services\Marketplaces\MercadoLivre;

use App\Services\Http\HttpTransport;
use App\Services\Http\TransportException;
use Config\MercadoLivre;

/**
 * Cliente HTTP fino da API do Mercado Livre: monta URL e cabeçalhos,
 * decodifica JSON e converte erros em MercadoLivreApiException.
 *
 * O access token vai sempre no cabeçalho Authorization, nunca na URL,
 * como pede a documentação oficial.
 */
class MercadoLivreApiClient
{
    private readonly MercadoLivre $config;
    private readonly HttpTransport $transport;

    public function __construct(?MercadoLivre $config = null, ?HttpTransport $transport = null)
    {
        $this->config    = $config ?? config(MercadoLivre::class);
        $this->transport = $transport ?? service('mercadoLivreTransport');
    }

    /**
     * POST /oauth/token com corpo form-urlencoded.
     *
     * @param array<string, string> $params
     */
    public function requestToken(array $params): array
    {
        return $this->send('POST', '/oauth/token', [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * @param array<string, scalar> $query
     */
    public function get(string $path, string $accessToken, array $query = []): array
    {
        $url = $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        return $this->send('GET', $url, $this->authHeaders($accessToken));
    }

    public function delete(string $path, string $accessToken): array
    {
        return $this->send('DELETE', $path, $this->authHeaders($accessToken));
    }

    /**
     * @param array<string, mixed> $body enviado como JSON
     */
    public function post(string $path, string $accessToken, array $body): array
    {
        return $this->send('POST', $path, $this->authHeaders($accessToken) + ['Content-Type' => 'application/json'], $this->encode($body));
    }

    /**
     * @param array<string, mixed> $body enviado como JSON
     */
    public function put(string $path, string $accessToken, array $body): array
    {
        return $this->send('PUT', $path, $this->authHeaders($accessToken) + ['Content-Type' => 'application/json'], $this->encode($body));
    }

    private function encode(array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(string $method, string $path, array $headers, ?string $body = null): array
    {
        $url = rtrim($this->config->apiUrl, '/') . '/' . ltrim($path, '/');

        try {
            $response = $this->transport->send($method, $url, $headers, $body);
        } catch (TransportException $e) {
            throw new MercadoLivreApiException($e->getMessage(), 0, 'connection_error', $e);
        }

        $data = $response->json();

        if (! $response->successful()) {
            throw MercadoLivreApiException::fromResponse($response->status, $data);
        }

        if ($data === null && trim($response->body) === '') {
            return [];
        }

        if (! is_array($data)) {
            throw new MercadoLivreApiException('Resposta inesperada (não é JSON).', $response->status, 'invalid_response');
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $accessToken): array
    {
        return [
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $accessToken,
        ];
    }
}

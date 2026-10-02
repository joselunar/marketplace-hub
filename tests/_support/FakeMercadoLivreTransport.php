<?php

namespace Tests\Support;

use App\Services\Http\HttpResponse;
use App\Services\Http\HttpTransport;
use App\Services\Http\TransportException;
use RuntimeException;

/**
 * Transporte em memória: devolve respostas enfileiradas e guarda as
 * requisições feitas, para os testes conferirem URL, cabeçalhos e corpo.
 */
final class FakeMercadoLivreTransport implements HttpTransport
{
    /**
     * @var list<HttpResponse|TransportException>
     */
    private array $queue = [];

    /**
     * @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}>
     */
    public array $requests = [];

    /**
     * @var array<string, list<HttpResponse|TransportException>>
     */
    private array $routes = [];

    public function push(int $status, array|string $body = []): self
    {
        $this->queue[] = new HttpResponse($status, is_string($body) ? $body : json_encode($body));

        return $this;
    }

    public function pushFailure(string $message = 'timeout'): self
    {
        $this->queue[] = new TransportException($message);

        return $this;
    }

    /**
     * Resposta fixa para um método + caminho (sem query string), usada antes da fila.
     * Várias respostas para o mesmo caminho são entregues em ordem; a última se repete.
     */
    public function on(string $method, string $path, int $status, array|string $body = []): self
    {
        $this->routes[strtoupper($method) . ' ' . $path][] = new HttpResponse($status, is_string($body) ? $body : json_encode($body));

        return $this;
    }

    public function routesReset(string $method, string $path): self
    {
        unset($this->routes[strtoupper($method) . ' ' . $path]);

        return $this;
    }

    public function onFailure(string $method, string $path, string $message = 'timeout'): self
    {
        $this->routes[strtoupper($method) . ' ' . $path][] = new TransportException($message);

        return $this;
    }

    /**
     * Requisições feitas para um método + caminho.
     *
     * @return list<array{method: string, url: string, headers: array<string, string>, body: ?string}>
     */
    public function requestsTo(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => $request['method'] === strtoupper($method) && parse_url($request['url'], PHP_URL_PATH) === $path,
        ));
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        $key = strtoupper($method) . ' ' . parse_url($url, PHP_URL_PATH);
        if (isset($this->routes[$key])) {
            $next = count($this->routes[$key]) > 1 ? array_shift($this->routes[$key]) : $this->routes[$key][0];
            if ($next instanceof TransportException) {
                throw $next;
            }

            return $next;
        }

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new RuntimeException("Requisição inesperada: {$method} {$url}");
        }
        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }

    /**
     * Corpo form-urlencoded da requisição N, decodificado.
     */
    public function formBody(int $index): array
    {
        parse_str((string) ($this->requests[$index]['body'] ?? ''), $params);

        return $params;
    }

    /**
     * Corpo JSON de uma requisição, decodificado.
     */
    public static function json(array $request): array
    {
        return json_decode((string) $request['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function pending(): int
    {
        return count($this->queue);
    }

    /**
     * Resposta de POST /oauth/token no formato da documentação.
     */
    public static function tokenResponse(string $access = 'APP_USR-1111-acesso-original-123456', ?string $refresh = 'TG-refresh-original-123456', int $userId = 123456): array
    {
        return array_filter([
            'access_token'  => $access,
            'token_type'    => 'bearer',
            'expires_in'    => 21600,
            'scope'         => 'offline_access read write',
            'user_id'       => $userId,
            'refresh_token' => $refresh,
        ], static fn ($value): bool => $value !== null);
    }
}

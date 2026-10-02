<?php

namespace Tests\Support;

use App\Services\Marketplaces\MercadoLivre\Http\HttpResponse;
use App\Services\Marketplaces\MercadoLivre\Http\HttpTransport;
use App\Services\Marketplaces\MercadoLivre\Http\TransportException;
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

    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

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

<?php

namespace App\Services\Marketplaces\MercadoLivre\Http;

/**
 * Envio HTTP cru. Existe para que os testes troquem a rede por respostas
 * gravadas sem precisar simular o cliente cURL do framework.
 */
interface HttpTransport
{
    /**
     * @param array<string, string> $headers
     *
     * @throws TransportException quando não há resposta (DNS, timeout, TLS...)
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}

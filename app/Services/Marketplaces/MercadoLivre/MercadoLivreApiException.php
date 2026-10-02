<?php

namespace App\Services\Marketplaces\MercadoLivre;

use RuntimeException;
use Throwable;

/**
 * Erro devolvido pela API do Mercado Livre (ou falta de resposta, status 0).
 *
 * A mensagem nunca carrega tokens: vem do corpo de erro da API, que só
 * traz código e descrição.
 */
class MercadoLivreApiException extends RuntimeException
{
    /**
     * Códigos de erro descritos na documentação de autenticação.
     */
    private const MESSAGES = [
        'invalid_client'           => 'Client ID ou Client Secret inválidos. Confira MELI_CLIENT_ID e MELI_CLIENT_SECRET no .env.',
        'invalid_grant'            => 'Autorização inválida, expirada ou revogada. Conecte a conta novamente.',
        'invalid_scope'            => 'Escopo de permissão inválido para o aplicativo.',
        'invalid_request'          => 'Requisição de autorização incompleta ou malformada.',
        'unsupported_grant_type'   => 'Tipo de concessão não suportado.',
        'forbidden'                => 'O Mercado Livre negou o acesso (token de outro usuário, IP bloqueado ou escopo ausente).',
        'local_rate_limited'       => 'Muitas requisições ao Mercado Livre. Tente novamente em alguns segundos.',
        'unauthorized_client'      => 'O aplicativo não tem permissão concedida por este usuário. Conecte a conta novamente.',
        'unauthorized_application' => 'O aplicativo está bloqueado no Mercado Livre.',
        'invalid_operator_user_id' => 'O login foi feito com um usuário operador/colaborador. Conecte com a conta administradora.',
    ];

    /**
     * Situações em que só uma nova autorização do vendedor resolve.
     */
    private const REAUTHORIZE = ['invalid_grant', 'unauthorized_client', 'invalid_operator_user_id'];

    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(int $status, mixed $body): self
    {
        $body  = is_array($body) ? $body : [];
        $code  = is_string($body['error'] ?? null) ? $body['error'] : null;
        $label = $body['message'] ?? $body['error_description'] ?? null;

        if ($status === 429 && $code === null) {
            $code = 'local_rate_limited';
        }

        $detail = is_string($label) && $label !== '' ? mb_substr($label, 0, 300) : 'HTTP ' . $status;

        return new self($detail, $status, $code);
    }

    public function requiresReauthorization(): bool
    {
        return in_array($this->errorCode, self::REAUTHORIZE, true);
    }

    /**
     * Texto para o usuário, em português, com o detalhe técnico ao final.
     */
    public function userMessage(): string
    {
        if ($this->status === 0) {
            return 'Não foi possível falar com o Mercado Livre agora. Verifique a conexão e tente novamente.';
        }

        $base = self::MESSAGES[$this->errorCode ?? ''] ?? match (true) {
            $this->status === 401 => 'Token recusado pelo Mercado Livre.',
            $this->status === 404 => 'Recurso não encontrado no Mercado Livre.',
            $this->status >= 500  => 'O Mercado Livre está instável no momento. Tente novamente mais tarde.',
            default               => 'O Mercado Livre recusou a requisição.',
        };

        return $base . ' (' . ($this->errorCode ?? 'HTTP ' . $this->status) . ': ' . $this->getMessage() . ')';
    }
}

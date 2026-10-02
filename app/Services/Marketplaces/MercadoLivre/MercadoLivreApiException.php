<?php

namespace App\Services\Marketplaces\MercadoLivre;

use App\Services\Marketplaces\Contracts\MarketplaceApiError;
use RuntimeException;
use Throwable;

/**
 * Erro devolvido pela API do Mercado Livre (ou falta de resposta, status 0).
 *
 * A mensagem nunca carrega tokens: vem do corpo de erro da API, que só
 * traz código, descrição e, nas validações de anúncio, a lista "cause".
 */
class MercadoLivreApiException extends RuntimeException implements MarketplaceApiError
{
    /**
     * Códigos de erro descritos na documentação de autenticação e de publicação.
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
        'validation_error'         => 'O Mercado Livre recusou os dados do anúncio.',
        'body.invalid_fields'      => 'Há campos inválidos para a categoria escolhida.',
        'seller.unable_to_list'    => 'O vendedor não está habilitado a anunciar no momento.',
        'item.category_id.invalid' => 'A categoria enviada não existe no Mercado Livre.',
    ];

    /**
     * Situações em que só uma nova autorização do vendedor resolve.
     */
    private const REAUTHORIZE = ['invalid_grant', 'unauthorized_client', 'invalid_operator_user_id'];

    private const MAX_CAUSES = 5;

    /**
     * @param list<array{code: string, message: string, type: string}> $causes
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $errorCode = null,
        ?Throwable $previous = null,
        public readonly array $causes = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(int $status, mixed $body): self
    {
        $body    = is_array($body) ? $body : [];
        $code    = is_string($body['error'] ?? null) ? $body['error'] : null;
        $label   = $body['message'] ?? $body['error_description'] ?? null;
        $message = is_string($body['message'] ?? null) ? $body['message'] : null;

        // Alguns erros de /items vêm invertidos: o código em "message" e a frase em "error".
        if ($code !== null && $message !== null && str_contains($code, ' ') && ! str_contains($message, ' ')) {
            [$code, $label] = [$message, $code];
        }

        if ($status === 429 && $code === null) {
            $code = 'local_rate_limited';
        }

        $detail = is_string($label) && $label !== '' ? mb_substr($label, 0, 300) : 'HTTP ' . $status;

        return new self($detail, $status, $code, null, self::parseCauses($body['cause'] ?? null));
    }

    public function requiresReauthorization(): bool
    {
        return in_array($this->errorCode, self::REAUTHORIZE, true);
    }

    public function isTemporary(): bool
    {
        return ! $this->requiresReauthorization()
            && ($this->status === 0 || $this->status === 429 || $this->status >= 500);
    }

    /**
     * Causas do tipo erro (os avisos não impedem a operação).
     *
     * @return list<string>
     */
    public function errorCauses(): array
    {
        $errors = array_filter($this->causes, static fn (array $cause): bool => $cause['type'] !== 'warning');

        return array_values(array_map(static fn (array $cause): string => $cause['message'] !== '' ? $cause['message'] : $cause['code'], $errors));
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

        $text   = $base . ' (' . ($this->errorCode ?? 'HTTP ' . $this->status) . ': ' . $this->getMessage() . ')';
        $causes = $this->errorCauses();

        if ($causes !== []) {
            $text .= ' Motivos: ' . implode('; ', array_slice($causes, 0, self::MAX_CAUSES)) . '.';
        }

        return $text;
    }

    /**
     * @return list<array{code: string, message: string, type: string}>
     */
    private static function parseCauses(mixed $causes): array
    {
        if (! is_array($causes)) {
            return [];
        }

        $parsed = [];

        foreach ($causes as $cause) {
            if (! is_array($cause)) {
                continue;
            }

            $parsed[] = [
                'code'    => mb_substr(is_string($cause['code'] ?? null) ? $cause['code'] : '', 0, 100),
                'message' => mb_substr(is_string($cause['message'] ?? null) ? $cause['message'] : '', 0, 300),
                'type'    => is_string($cause['type'] ?? null) ? $cause['type'] : 'error',
            ];
        }

        return $parsed;
    }
}

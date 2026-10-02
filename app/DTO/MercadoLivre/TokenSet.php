<?php

namespace App\DTO\MercadoLivre;

use App\Services\Marketplaces\MercadoLivre\MercadoLivreApiException;

/**
 * Resposta de POST /oauth/token (authorization_code ou refresh_token).
 */
final class TokenSet
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly int $expiresIn,
        public readonly string $userId,
        public readonly ?string $scope,
    ) {
    }

    public static function fromResponse(array $data): self
    {
        $accessToken = $data['access_token'] ?? null;
        $userId      = $data['user_id'] ?? null;

        if (! is_string($accessToken) || $accessToken === '' || ! is_scalar($userId) || (string) $userId === '') {
            throw new MercadoLivreApiException('A resposta de token não trouxe access_token e user_id.', 200, 'invalid_response');
        }

        $refreshToken = $data['refresh_token'] ?? null;

        return new self(
            $accessToken,
            is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            max(0, (int) ($data['expires_in'] ?? 0)),
            (string) $userId,
            is_string($data['scope'] ?? null) ? mb_substr($data['scope'], 0, 255) : null,
        );
    }

    /**
     * Data de expiração calculada a partir de expires_in (segundos).
     */
    public function expiresAt(int $now): string
    {
        return date('Y-m-d H:i:s', $now + $this->expiresIn);
    }
}

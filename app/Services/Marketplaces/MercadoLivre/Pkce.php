<?php

namespace App\Services\Marketplaces\MercadoLivre;

/**
 * Proof Key for Code Exchange (RFC 7636), método S256.
 */
final class Pkce
{
    public const METHOD = 'S256';

    /**
     * 64 caracteres do alfabeto URL-safe (o RFC aceita de 43 a 128).
     */
    public static function verifier(): string
    {
        return self::base64Url(random_bytes(48));
    }

    public static function challenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

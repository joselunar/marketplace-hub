<?php

namespace App\Services\Security;

use App\Exceptions\BusinessRuleException;
use CodeIgniter\Encryption\EncrypterInterface;
use CodeIgniter\Encryption\Exceptions\EncryptionException;

/**
 * Cifra tokens de integração antes de irem para o banco.
 *
 * Usa o encrypter do framework (AES-256-CTR + HMAC-SHA512) com a chave
 * encryption.key do .env. O valor salvo é base64 do texto cifrado.
 */
class TokenCipher
{
    public function __construct(private ?EncrypterInterface $encrypter = null)
    {
    }

    public function encrypt(string $plainText): string
    {
        return base64_encode($this->encrypter()->encrypt($plainText));
    }

    public function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $raw = base64_decode($stored, true);

        try {
            if ($raw === false) {
                throw new EncryptionException('Conteúdo cifrado inválido.');
            }

            return $this->encrypter()->decrypt($raw);
        } catch (EncryptionException $e) {
            log_message('error', 'Falha ao decifrar token de integração: {message}', ['message' => $e->getMessage()]);

            throw new BusinessRuleException('Não foi possível ler o token salvo. Se a chave encryption.key foi trocada, conecte a conta novamente.', 0, $e);
        }
    }

    /**
     * Versão para exibição: só o prefixo e os 4 últimos caracteres.
     */
    public static function mask(?string $token): string
    {
        if ($token === null || $token === '') {
            return '—';
        }

        if (strlen($token) <= 12) {
            return str_repeat('•', 8);
        }

        $prefix = str_contains($token, '-') ? strstr($token, '-', true) . '-' : substr($token, 0, 4);

        return mb_substr($prefix, 0, 8) . '••••' . substr($token, -4);
    }

    private function encrypter(): EncrypterInterface
    {
        if ($this->encrypter === null) {
            try {
                $this->encrypter = service('encrypter');
            } catch (EncryptionException $e) {
                throw new BusinessRuleException('Defina encryption.key no .env (php spark key:generate) antes de conectar contas de marketplace.', 0, $e);
            }
        }

        return $this->encrypter;
    }
}

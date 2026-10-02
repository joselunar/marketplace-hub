<?php

namespace Tests\Unit\MercadoLivre;

use App\DTO\MercadoLivre\TokenSet;
use App\Exceptions\BusinessRuleException;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreApiException;
use App\Services\Marketplaces\MercadoLivre\Pkce;
use App\Services\Security\TokenCipher;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Encryption;
use Config\Services;

final class MercadoLivreSupportTest extends CIUnitTestCase
{
    public function testPkceChallengeMatchesRfc7636Example(): void
    {
        // Vetor do apêndice B da RFC 7636.
        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));

        $verifier = Pkce::verifier();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]{43,128}$/', $verifier);
        $this->assertNotSame($verifier, Pkce::verifier());
    }

    public function testCipherRoundTripAndTamperDetection(): void
    {
        $cipher = new TokenCipher($this->encrypter());
        $stored = $cipher->encrypt('APP_USR-123-segredo-999');

        $this->assertStringNotContainsString('segredo', $stored);
        $this->assertNotSame($stored, $cipher->encrypt('APP_USR-123-segredo-999'), 'IV aleatório: mesmo texto gera cifras diferentes');
        $this->assertSame('APP_USR-123-segredo-999', $cipher->decrypt($stored));
        $this->assertNull($cipher->decrypt(null));

        $tampered = base64_encode(substr(base64_decode($stored), 0, -1) . 'x');
        $this->expectException(BusinessRuleException::class);
        $cipher->decrypt($tampered);
    }

    public function testCipherWithAnotherKeyCannotRead(): void
    {
        $stored = (new TokenCipher($this->encrypter()))->encrypt('APP_USR-123-segredo');

        $this->expectException(BusinessRuleException::class);
        (new TokenCipher($this->encrypter()))->decrypt($stored);
    }

    public function testMaskNeverRevealsTheWholeToken(): void
    {
        $this->assertSame('APP_USR-••••3456', TokenCipher::mask('APP_USR-1234-0915-8cc4448aac10d5105474e1351-123456'));
        $this->assertSame('TG-••••3456', TokenCipher::mask('TG-5b9032b4e23464aed1f959f-123456'));
        $this->assertSame('••••••••', TokenCipher::mask('curto'));
        $this->assertSame('—', TokenCipher::mask(null));
    }

    public function testTokenSetFromDocumentedResponse(): void
    {
        $tokens = TokenSet::fromResponse([
            'access_token'  => 'APP_USR-123456-090515-8cc4448aac10d5105474e1351-1234567',
            'token_type'    => 'bearer',
            'expires_in'    => 21600,
            'scope'         => 'offline_access read write',
            'user_id'       => 1234567,
            'refresh_token' => 'TG-5b9032b4e23464aed1f959f-1234567',
        ]);

        $this->assertSame('1234567', $tokens->userId);
        $this->assertSame('2026-10-02 06:00:00', $tokens->expiresAt(strtotime('2026-10-02 00:00:00')));

        $withoutRefresh = TokenSet::fromResponse(['access_token' => 'APP_USR-x', 'user_id' => 1, 'expires_in' => 21600]);
        $this->assertNull($withoutRefresh->refreshToken);

        $this->expectException(MercadoLivreApiException::class);
        TokenSet::fromResponse(['token_type' => 'bearer']);
    }

    public function testApiErrorsAreTranslated(): void
    {
        $grant = MercadoLivreApiException::fromResponse(400, [
            'error_description' => 'Error validating grant. Your authorization code or refresh token may be expired or it was already used',
            'error'             => 'invalid_grant',
            'status'            => 400,
        ]);
        $this->assertTrue($grant->requiresReauthorization());
        $this->assertStringContainsString('Conecte a conta novamente', $grant->userMessage());
        $this->assertStringContainsString('invalid_grant', $grant->userMessage());

        $client = MercadoLivreApiException::fromResponse(400, ['error' => 'invalid_client', 'message' => 'invalid client_id or client_secret']);
        $this->assertFalse($client->requiresReauthorization(), 'Credencial errada do app não é culpa da conta');
        $this->assertStringContainsString('MELI_CLIENT_SECRET', $client->userMessage());

        $this->assertSame('local_rate_limited', MercadoLivreApiException::fromResponse(429, null)->errorCode);
        $this->assertStringContainsString('instável', MercadoLivreApiException::fromResponse(503, 'erro')->userMessage());
        $this->assertStringContainsString('Não foi possível falar', (new MercadoLivreApiException('timeout'))->userMessage());
    }

    private function encrypter(): \CodeIgniter\Encryption\EncrypterInterface
    {
        $config      = new Encryption();
        $config->key = random_bytes(32);

        return Services::encrypter($config, false);
    }
}

<?php

namespace Tests\Unit\Audit;

use App\Services\Audit\AuditService;
use CodeIgniter\Test\CIUnitTestCase;

final class AuditSanitizeTest extends CIUnitTestCase
{
    public function testMasksCredentialsRecursively(): void
    {
        $sanitized = AuditService::sanitize([
            'name'          => 'Fulano',
            'password'      => 'segredo',
            'password_hash' => '$2y$...',
            'nested'        => [
                'access_token'  => 'abc',
                'client_secret' => 'xyz',
                'api_key'       => '123',
                'price'         => '10.00',
            ],
        ]);

        $this->assertSame('Fulano', $sanitized['name']);
        $this->assertSame('[REDACTED]', $sanitized['password']);
        $this->assertSame('[REDACTED]', $sanitized['password_hash']);
        $this->assertSame('[REDACTED]', $sanitized['nested']['access_token']);
        $this->assertSame('[REDACTED]', $sanitized['nested']['client_secret']);
        $this->assertSame('[REDACTED]', $sanitized['nested']['api_key']);
        $this->assertSame('10.00', $sanitized['nested']['price']);
    }
}

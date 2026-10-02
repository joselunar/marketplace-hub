<?php

namespace Tests\Feature;

use CodeIgniter\Security\Exceptions\SecurityException;
use Tests\Support\FeatureTestCase;

final class CsrfTest extends FeatureTestCase
{
    protected bool $disableCsrf = false;

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $user = $this->createUser('admin');

        $this->expectException(SecurityException::class);

        $this->actingAs($user)->post('suppliers', [
            'name'             => 'Sem token',
            'integration_type' => 'manual',
            'status'           => 'active',
            'priority'         => '5',
        ]);
    }
}

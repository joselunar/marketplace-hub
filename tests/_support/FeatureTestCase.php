<?php

namespace Tests\Support;

use CodeIgniter\Test\FeatureTestTrait;
use Config\Filters;

/**
 * Base para testes HTTP. O CSRF é desligado aqui porque já é coberto
 * por um teste próprio (CsrfTest); os demais testes focam no comportamento.
 */
abstract class FeatureTestCase extends DatabaseTestCase
{
    use FeatureTestTrait;

    protected bool $disableCsrf = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->disableCsrf) {
            $filters = config(Filters::class);
            unset($filters->globals['before']['csrf']);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    protected function actingAs(array $user): static
    {
        return $this->withSession(['user_id' => (int) $user['id'], 'user_role' => $user['role']]);
    }
}

<?php

namespace Tests\Support;

use App\Jobs\JobInterface;
use Throwable;

/**
 * Job de teste: a cada execução consome o próximo item do roteiro
 * (exceção para lançar ou texto de sucesso).
 */
final class ScriptedJob implements JobInterface
{
    public const TYPE = 'scripted';

    /**
     * @var list<string|Throwable>
     */
    public static array $script = [];

    /**
     * @var list<array<string, mixed>>
     */
    public static array $payloads = [];

    public static function label(): string
    {
        return 'Job de teste';
    }

    public static function reset(): void
    {
        self::$script   = [];
        self::$payloads = [];
    }

    public function handle(array $payload): string
    {
        self::$payloads[] = $payload;
        $next             = array_shift(self::$script) ?? 'ok';

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}

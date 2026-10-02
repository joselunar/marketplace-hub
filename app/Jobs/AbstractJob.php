<?php

namespace App\Jobs;

use InvalidArgumentException;

abstract class AbstractJob implements JobInterface
{
    /**
     * ID positivo obrigatório do payload.
     */
    protected function id(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException(sprintf('Payload sem "%s" válido.', $key));
        }
        if ((int) $value < 1) {
            throw new InvalidArgumentException(sprintf('Payload sem "%s" válido.', $key));
        }

        return (int) $value;
    }
}

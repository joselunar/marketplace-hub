<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Dados recebidos não passaram na validação de backend.
 */
class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(private readonly array $errors, string $message = 'Os dados informados são inválidos.')
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}

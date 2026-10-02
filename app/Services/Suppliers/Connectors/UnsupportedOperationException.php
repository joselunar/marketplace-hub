<?php

namespace App\Services\Suppliers\Connectors;

use RuntimeException;

class UnsupportedOperationException extends RuntimeException
{
    public static function for(string $connector, string $capability): self
    {
        return new self(sprintf('O conector %s não suporta a operação "%s".', $connector, $capability));
    }
}

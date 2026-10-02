<?php

namespace App\Services\Http;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Corpo decodificado; null quando não é JSON.
     */
    public function json(): mixed
    {
        if (trim($this->body) === '') {
            return null;
        }

        try {
            return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}

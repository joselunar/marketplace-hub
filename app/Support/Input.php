<?php

namespace App\Support;

/**
 * Pequenas rotinas de limpeza de entrada usadas pelos services
 * antes da validação dos models.
 */
final class Input
{
    /**
     * Mantém apenas as chaves permitidas, aplica trim em strings e
     * converte strings vazias em null.
     *
     * @param list<string> $allowed
     *
     * @return array<string, mixed>
     */
    public static function only(array $data, array $allowed): array
    {
        $clean = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];

            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    public static function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    /**
     * Checkbox/selects "0"/"1" → 0|1.
     */
    public static function flag(mixed $value): int
    {
        return in_array($value, [true, 1, '1', 'on', 'true'], true) ? 1 : 0;
    }

    public static function slug(string $text): string
    {
        helper('text');

        return url_title(convert_accented_characters($text), '-', true);
    }
}

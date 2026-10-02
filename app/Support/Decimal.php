<?php

namespace App\Support;

/**
 * Normalização de valores decimais digitados pelo usuário ou vindos de arquivos.
 *
 * Valores monetários trafegam como string ("1234.56") para evitar
 * imprecisão de ponto flutuante antes de chegar ao DECIMAL do MySQL.
 */
final class Decimal
{
    /**
     * Converte "1.234,56", "1234,56", "1,234.56" ou "1234.56" em "1234.56".
     * Retorna null quando o valor está vazio ou não é numérico.
     */
    public static function normalize(mixed $value, int $scale = 2): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return number_format($value, $scale, '.', '');
        }

        $value = trim((string) $value);
        $value = preg_replace('/^R\$\s*/i', '', $value) ?? '';
        $value = str_replace(' ', '', $value);

        if ($value === '') {
            return null;
        }

        $lastComma = strrpos($value, ',');
        $lastDot   = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // O separador que aparece por último é o decimal.
            $decimalSeparator  = $lastComma > $lastDot ? ',' : '.';
            $thousandSeparator = $decimalSeparator === ',' ? '.' : ',';
            $value             = str_replace($thousandSeparator, '', $value);
            $value             = str_replace($decimalSeparator, '.', $value);
        } elseif ($lastComma !== false) {
            if (substr_count($value, ',') > 1) {
                return null;
            }
            $value = str_replace(',', '.', $value);
        } elseif ($lastDot !== false && substr_count($value, '.') > 1) {
            // "1.234.567" sem casas decimais.
            $value = str_replace('.', '', $value);
        }

        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return null;
        }

        return self::round($value, $scale);
    }

    /**
     * Arredonda (meio para cima) uma string numérica sem passar por float.
     */
    public static function round(string $value, int $scale = 2): string
    {
        $negative = str_starts_with($value, '-');
        $value    = ltrim($value, '-');

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer              = ltrim($integer, '0') ?: '0';
        $fraction             = str_pad($fraction, $scale + 1, '0');

        $kept      = substr($fraction, 0, $scale);
        $roundUp   = (int) $fraction[$scale] >= 5;
        $digits    = $integer . $kept;

        if ($roundUp) {
            $digits = self::incrementDigits($digits);
        }

        $integerPart  = substr($digits, 0, strlen($digits) - $scale) ?: '0';
        $fractionPart = $scale > 0 ? substr($digits, -$scale) : '';
        $result       = $scale > 0 ? $integerPart . '.' . $fractionPart : $integerPart;

        $isZero = trim(str_replace('.', '', $result), '0') === '';

        return ($negative && ! $isZero) ? '-' . $result : $result;
    }

    private static function incrementDigits(string $digits): string
    {
        $i = strlen($digits) - 1;

        while ($i >= 0) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);

                return $digits;
            }
            $digits[$i] = '0';
            $i--;
        }

        return '1' . $digits;
    }
}

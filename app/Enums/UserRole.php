<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin    = 'admin';
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::Admin    => 'Administrador',
            self::Operator => 'Operador',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public static function inListRule(): string
    {
        return 'in_list[' . implode(',', array_column(self::cases(), 'value')) . ']';
    }
}

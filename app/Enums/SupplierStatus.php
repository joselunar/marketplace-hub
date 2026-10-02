<?php

namespace App\Enums;

enum SupplierStatus: string
{
    case Active   = 'active';
    case Inactive = 'inactive';
    case Blocked  = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Active   => 'Ativo',
            self::Inactive => 'Inativo',
            self::Blocked  => 'Bloqueado',
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

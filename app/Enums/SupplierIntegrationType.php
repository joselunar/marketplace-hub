<?php

namespace App\Enums;

enum SupplierIntegrationType: string
{
    case Api    = 'api';
    case Csv    = 'csv';
    case Xml    = 'xml';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Api    => 'API',
            self::Csv    => 'Planilha CSV',
            self::Xml    => 'Arquivo XML',
            self::Manual => 'Cadastro manual',
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

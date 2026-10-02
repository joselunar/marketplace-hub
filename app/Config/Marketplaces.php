<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Marketplaces conhecidos pelo sistema (slug => nome). A linha na tabela
 * marketplaces é criada na primeira vez que o marketplace é usado.
 */
class Marketplaces extends BaseConfig
{
    /**
     * @var array<string, string>
     */
    public array $registry = [
        'mercadolivre' => 'Mercado Livre',
    ];
}

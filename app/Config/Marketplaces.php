<?php

namespace Config;

use App\Services\Marketplaces\MercadoLivre\MercadoLivreAdapter;
use CodeIgniter\Config\BaseConfig;

/**
 * Marketplaces integrados: slug => classe do adaptador
 * (App\Services\Marketplaces\Contracts\MarketplaceAdapter). A linha na
 * tabela marketplaces é criada na primeira vez que o marketplace é usado.
 */
class Marketplaces extends BaseConfig
{
    /**
     * @var array<string, class-string<\App\Services\Marketplaces\Contracts\MarketplaceAdapter>>
     */
    public array $adapters = [
        'mercadolivre' => MercadoLivreAdapter::class,
    ];
}

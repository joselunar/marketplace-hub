<?php

namespace App\Controllers\Api;

use App\Services\Marketplaces\MarketplaceService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Marketplaces integrados e o que cada adaptador oferece. Credenciais nunca
 * saem daqui: só os nomes das variáveis de ambiente que faltam.
 */
class MarketplaceController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $data = array_map(static fn (array $marketplace): array => [
            'slug'            => $marketplace['slug'],
            'name'            => $marketplace['name'],
            'configured'      => $marketplace['configured'],
            'missing'         => $marketplace['missing'],
            'capabilities'    => array_keys($marketplace['capabilities']),
            'connected'       => $marketplace['connected'],
            'reconnect'       => $marketplace['reconnect'],
            'active_listings' => $marketplace['active_listings'],
            'fees'            => $marketplace['effective_fees'],
        ], (new MarketplaceService())->overview());

        return $this->respond(['data' => $data]);
    }
}

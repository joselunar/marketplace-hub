<?php

namespace App\Controllers\Api;

use App\Services\Marketplaces\MarketplaceAccountService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Contas conectadas. Nenhum token (nem mascarado) sai por aqui.
 */
class MarketplaceAccountController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $service  = new MarketplaceAccountService();
        $registry = service('marketplaces');
        $slug     = $this->queryString('marketplace');
        $slugs    = $slug !== null ? [$slug] : array_keys($registry->all());

        if ($slug !== null && ! $registry->has($slug)) {
            return $this->failValidationErrors(['marketplace' => 'Marketplace desconhecido.']);
        }

        $data = [];
        foreach ($slugs as $current) {
            foreach ($service->listFor($current) as $account) {
                $data[] = [
                    'id'                 => (int) $account['id'],
                    'marketplace'        => $account['marketplace_slug'],
                    'name'               => $account['name'],
                    'external_user_id'   => $account['external_user_id'],
                    'site_id'            => $account['site_id'],
                    'status'             => $account['status'],
                    'active'             => (int) $account['active'] === 1,
                    'token_expires_at'   => $account['token_expires_at'],
                    'token_expired'      => $account['token_expired'],
                    'token_refreshed_at' => $account['token_refreshed_at'],
                    'last_error'         => $account['last_error'],
                    'connected_at'       => $account['connected_at'],
                ];
            }
        }

        return $this->respond(['data' => $data]);
    }
}

<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\MarketplaceAccountModel;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreApiException;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreAuthService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreCategoryService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreService;

/**
 * Explorador de categorias e atributos do Mercado Livre, base para o
 * mapeamento de categorias e a validação de anúncios da próxima fase.
 */
class MercadoLivreCategoryController extends BaseController
{
    public function index(): string
    {
        /** @var MercadoLivreService $meli */
        $meli     = service('mercadoLivre');
        $filters  = $this->filters(['account', 'category', 'q']);
        $accounts = array_values(array_filter(
            $meli->accounts()->listFor(MercadoLivreAuthService::SLUG),
            static fn (array $account): bool => $account['status'] === MarketplaceAccountModel::STATUS_CONNECTED && (int) $account['active'] === 1,
        ));

        $data = [
            'title'       => 'Categorias do Mercado Livre',
            'tab'         => 'categories',
            'accounts'    => $accounts,
            'filters'     => $filters,
            'account'     => null,
            'roots'       => [],
            'category'    => null,
            'attributes'  => [],
            'predictions' => null,
            'error'       => null,
            'breadcrumbs' => ['Marketplaces' => null, 'Mercado Livre' => 'marketplaces/mercadolivre', 'Categorias' => null],
        ];

        if ($accounts === []) {
            return view('mercadolivre/categories', $data);
        }

        try {
            $service         = new MercadoLivreCategoryService($meli);
            $accountId       = isset($filters['account']) && ctype_digit($filters['account']) ? (int) $filters['account'] : null;
            $data['account'] = $meli->resolveAccount($accountId);

            if (isset($filters['q'])) {
                $data['predictions'] = $service->predict($data['account'], $filters['q']);
            }

            if (isset($filters['category'])) {
                $data['category'] = $service->category($data['account'], $filters['category']);
                if ($data['category']['is_leaf']) {
                    $data['attributes'] = $service->attributes($data['account'], $data['category']['id']);
                }
            } elseif (! isset($filters['q'])) {
                $data['roots'] = $service->roots($data['account']);
            }
        } catch (ValidationException $e) {
            $data['error'] = implode(' ', $e->getErrors());
        } catch (BusinessRuleException $e) {
            $data['error'] = $e->getMessage();
        } catch (MercadoLivreApiException $e) {
            $data['error'] = $e->userMessage();
        }

        return view('mercadolivre/categories', $data);
    }
}

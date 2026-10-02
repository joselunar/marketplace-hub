<?php

namespace App\Controllers;

use App\DTO\Pricing\PricingConfig;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\MarketplaceModel;
use App\Services\Marketplaces\MarketplaceService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Marketplaces integrados: situação da integração, contas, anúncios e tarifas padrão.
 */
class MarketplaceController extends BaseController
{
    private const HOME = 'marketplaces';

    public function index(): string
    {
        return view('marketplaces/index', [
            'title'        => 'Marketplaces',
            'marketplaces' => (new MarketplaceService())->overview(),
            'feeFields'    => MarketplaceModel::FEE_FIELDS,
            'global'       => PricingConfig::fromSettings(service('settings')),
            'breadcrumbs'  => ['Marketplaces' => null],
        ]);
    }

    public function updateFees(string $slug): RedirectResponse
    {
        $input = array_intersect_key((array) $this->request->getPost(), MarketplaceModel::FEE_FIELDS);

        try {
            (new MarketplaceService())->updateFees($slug, $input, $this->auth()->id());
        } catch (ValidationException $e) {
            return redirect()->to(self::HOME)->withInput()->with('errors', $e->getErrors())->with('fees_slug', $slug);
        } catch (BusinessRuleException $e) {
            return redirect()->to(self::HOME)->with('error', $e->getMessage());
        }

        return redirect()->to(self::HOME)->with('success', 'Tarifas do marketplace salvas. Os preços sugeridos de novos anúncios e o lucro estimado dos pedidos passam a usar esses valores.');
    }
}

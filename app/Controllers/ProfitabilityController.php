<?php

namespace App\Controllers;

use App\DTO\Profitability\ProfitabilityResult;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\CategoryModel;
use App\Models\ProfitabilityAnalysisHistoryModel;
use App\Models\ProfitabilityAnalysisModel;
use App\Models\SupplierModel;
use App\Models\SupplierOfferModel;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreLogisticsService;
use App\Services\Profitability\HomologationChecklistService;
use App\Services\Profitability\ProfitabilityCategoryService;
use App\Services\Profitability\ProfitabilityService;
use CodeIgniter\HTTP\RedirectResponse;

class ProfitabilityController extends BaseController
{
    public function index(): string|RedirectResponse
    {
        if (! $this->service()->enabled()) {
            return redirect()->to('dashboard')->with('error', 'O módulo de rentabilidade está desativado nas configurações.');
        }

        $filters = $this->filters(['q', 'supplier_id', 'classification', 'category_id', 'min_price', 'max_price']);
        $model   = new ProfitabilityAnalysisModel();
        $rows    = $model->withProduct()
            ->applyFilters($filters)
            ->orderBy('profitability_analyses.contribution_margin_pct', 'DESC')
            ->orderBy('profitability_analyses.id', 'DESC')
            ->paginate($this->perPage());

        return view('profitability/index', [
            'title'       => 'Rentabilidade',
            'rows'        => $rows,
            'pager'       => $model->pager,
            'filters'     => $filters,
            'counts'      => $model->countsByClassification(
                isset($filters['supplier_id']) ? (int) $filters['supplier_id'] : null
            ),
            'top'         => $this->service()->topMargins(8),
            'labels'      => ProfitabilityResult::LABELS,
            'suppliers'   => (new SupplierModel())->dropdownOptions(),
            'categories'  => (new CategoryModel())->dropdownOptions(),
            'breadcrumbs' => ['Catálogo' => null, 'Rentabilidade' => null],
        ]);
    }

    public function show(int $productId): string|RedirectResponse
    {
        $analysis = (new ProfitabilityAnalysisModel())->withProduct()
            ->where('profitability_analyses.product_id', $productId)
            ->first();

        if ($analysis === null) {
            try {
                $analysis = $this->service()->analyzeProduct($productId, $this->auth()->id(), 'view');
            } catch (BusinessRuleException $e) {
                return redirect()->to('profitability')->with('error', $e->getMessage());
            }
        }

        $breakdown = json_decode((string) ($analysis['breakdown'] ?? '{}'), true) ?: [];
        $product   = model(\App\Models\ProductModel::class)->find($productId) ?? [];
        $offer     = (new SupplierOfferModel())->where('product_id', $productId)->orderBy('id', 'DESC')->first();
        $catSvc    = new ProfitabilityCategoryService();
        $account   = $catSvc->resolveAccount();
        $suggestions = [];
        if ($account !== null) {
            $suggestions = $catSvc->suggest($product, $offer, $account);
        }

        return view('profitability/show', [
            'title'        => 'Análise · ' . ($analysis['product_name'] ?? ('#' . $productId)),
            'analysis'     => $analysis,
            'breakdown'    => $breakdown,
            'history'      => (new ProfitabilityAnalysisHistoryModel())->forProduct($productId),
            'labels'       => ProfitabilityResult::LABELS,
            'product'      => $product,
            'offer'        => $offer,
            'suggestions'  => $suggestions,
            'breadcrumbs'  => ['Rentabilidade' => 'profitability', $analysis['sku'] ?? 'Produto' => null],
        ]);
    }

    public function recalculate(int $productId): RedirectResponse
    {
        try {
            $this->service()->analyzeProduct($productId, $this->auth()->id(), 'recalculate');
        } catch (BusinessRuleException $e) {
            return redirect()->to('profitability/' . $productId)->with('error', $e->getMessage());
        }

        return redirect()->to('profitability/' . $productId)->with('success', 'Análise recalculada.');
    }

    public function simulate(int $productId): RedirectResponse
    {
        $price = (string) $this->request->getPost('sale_price');

        try {
            $this->service()->simulate($productId, $price, true, $this->auth()->id());
        } catch (BusinessRuleException $e) {
            return redirect()->to('profitability/' . $productId)->with('error', $e->getMessage());
        }

        return redirect()->to('profitability/' . $productId)->with('success', 'Simulação aplicada e registrada no histórico.');
    }

    public function confirmCategory(int $productId): RedirectResponse
    {
        $categoryId = (string) $this->request->getPost('category_id');
        $remember   = $this->request->getPost('remember_mapping') !== '0';
        $origin     = (string) $this->request->getPost('origin') === ProfitabilityCategoryService::SOURCE_MANUAL
            ? ProfitabilityCategoryService::SOURCE_MANUAL
            : ProfitabilityCategoryService::SOURCE_CONFIRMED;

        try {
            $result = $this->service()->confirmCategory(
                $productId,
                $categoryId,
                $this->auth()->id(),
                $remember,
                $origin,
            );
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $message = $errors !== [] ? implode(' ', $errors) : $e->getMessage();

            return redirect()->to('profitability/' . $productId)->with('error', $message);
        } catch (BusinessRuleException $e) {
            return redirect()->to('profitability/' . $productId)->with('error', $e->getMessage());
        }

        return redirect()->to('profitability/' . $productId)->with(
            'success',
            'Categoria MLB confirmada. Análises recalculadas: ' . $result['recalculated'] . '.',
        );
    }

    public function ranking(): string|RedirectResponse
    {
        if (! $this->service()->enabled()) {
            return redirect()->to('dashboard')->with('error', 'O módulo de rentabilidade está desativado nas configurações.');
        }

        $filters = $this->filters(['q', 'supplier_id', 'classification', 'min_indicative', 'only_with_fee']);

        return view('profitability/ranking', [
            'title'       => 'Ranking de oportunidades',
            'rows'        => $this->service()->ranking($filters, 200),
            'filters'     => $filters,
            'labels'      => ProfitabilityResult::LABELS,
            'suppliers'   => (new SupplierModel())->dropdownOptions(),
            'breadcrumbs' => ['Rentabilidade' => 'profitability', 'Ranking' => null],
        ]);
    }

    public function logistics(): string|RedirectResponse
    {
        $catSvc  = new ProfitabilityCategoryService();
        $account = $catSvc->resolveAccount();
        $context = null;
        $error   = null;

        if ($account === null) {
            $error = 'Nenhuma conta Mercado Livre conectada.';
        } else {
            try {
                $context = (new MercadoLivreLogisticsService())->resolveQuoteContext($account);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $settings = service('settings');

        return view('profitability/logistics', [
            'title'       => 'Logística ML',
            'account'     => $account,
            'context'     => $context,
            'error'       => $error,
            'currentMode' => (string) $settings->get('meli.shipping_mode', ''),
            'currentType' => (string) $settings->get('meli.logistic_type', ''),
            'breadcrumbs' => ['Rentabilidade' => 'profitability', 'Logística ML' => null],
        ]);
    }

    public function confirmLogistics(): RedirectResponse
    {
        try {
            $result = $this->service()->confirmLogistics(
                (string) $this->request->getPost('shipping_mode'),
                (string) $this->request->getPost('logistic_type'),
                $this->auth()->id(),
            );
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $message = $errors !== [] ? implode(' ', $errors) : $e->getMessage();

            return redirect()->to('profitability/logistics')->with('error', $message);
        } catch (BusinessRuleException $e) {
            return redirect()->to('profitability/logistics')->with('error', $e->getMessage());
        }

        return redirect()->to('profitability/logistics')->with(
            'success',
            'Logística confirmada. Análises recalculadas: ' . $result['recalculated'] . '.',
        );
    }

    public function checklist(): string
    {
        $service = new HomologationChecklistService();

        return view('profitability/checklist', [
            'title'       => 'Checklist Dropify',
            'items'       => $service->list(),
            'message'     => $service->outreachMessage(),
            'statuses'    => \App\Models\HomologationChecklistItemModel::STATUS_LABELS,
            'ready'       => $this->service()->logisticsChecklistReady(),
            'breadcrumbs' => ['Rentabilidade' => 'profitability', 'Checklist Dropify' => null],
        ]);
    }

    public function updateChecklist(int $id): RedirectResponse
    {
        try {
            (new HomologationChecklistService())->updateItem(
                $id,
                (string) $this->request->getPost('status'),
                $this->request->getPost('evidence_note'),
                $this->auth()->id(),
            );
        } catch (BusinessRuleException $e) {
            return redirect()->to('profitability/checklist')->with('error', $e->getMessage());
        }

        return redirect()->to('profitability/checklist')->with('success', 'Item do checklist atualizado.');
    }

    private function service(): ProfitabilityService
    {
        return service('profitability');
    }
}

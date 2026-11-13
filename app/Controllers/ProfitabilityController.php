<?php

namespace App\Controllers;

use App\DTO\Profitability\ProfitabilityResult;
use App\Exceptions\BusinessRuleException;
use App\Models\CategoryModel;
use App\Models\ProfitabilityAnalysisHistoryModel;
use App\Models\ProfitabilityAnalysisModel;
use App\Models\SupplierModel;
use App\Services\Profitability\HomologationChecklistService;
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

        return view('profitability/show', [
            'title'       => 'Análise · ' . ($analysis['product_name'] ?? ('#' . $productId)),
            'analysis'    => $analysis,
            'breakdown'   => $breakdown,
            'history'     => (new ProfitabilityAnalysisHistoryModel())->forProduct($productId),
            'labels'      => ProfitabilityResult::LABELS,
            'breadcrumbs' => ['Rentabilidade' => 'profitability', $analysis['sku'] ?? 'Produto' => null],
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

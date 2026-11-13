<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Services\Profitability\CommercialIntelligenceService;
use App\Services\Profitability\MonthlyOpsSimulator;
use CodeIgniter\HTTP\RedirectResponse;

class CommercialIntelligenceController extends BaseController
{
    public function index(): string|RedirectResponse
    {
        if (! service('profitability')->enabled()) {
            return redirect()->to('dashboard')->with('error', 'O módulo de rentabilidade está desativado nas configurações.');
        }

        $rows = $this->service()->portfolio($this->auth()->id(), false);

        return view('profitability/commercial_index', [
            'title'       => 'Inteligência comercial',
            'rows'        => $rows,
            'breadcrumbs' => ['Rentabilidade' => 'profitability', 'Inteligência comercial' => null],
            'cepPending'  => true,
        ]);
    }

    public function refreshAll(): RedirectResponse
    {
        // Cota apenas o próximo produto sem snapshot, para não estourar o timeout HTTP.
        foreach ($this->service()->realDropifyProducts() as $product) {
            $id = (int) $product['id'];
            $existing = (new \App\Models\CommercialIntelligenceModel())->findForProduct($id);
            if ($existing !== null) {
                continue;
            }

            try {
                $this->service()->refreshProduct($id, $this->auth()->id());

                return redirect()->to('profitability/commercial')->with(
                    'success',
                    'Cotado: ' . ($product['sku'] ?? ('#' . $id)) . '. Clique novamente em Atualizar portfólio para o próximo.',
                );
            } catch (BusinessRuleException $e) {
                return redirect()->to('profitability/commercial')->with(
                    'error',
                    ($product['sku'] ?? ('#' . $id)) . ': ' . $e->getMessage(),
                );
            }
        }

        return redirect()->to('profitability/commercial')->with(
            'success',
            'Todos os produtos Dropify já possuem snapshot comercial. Use Recotar no detalhe para atualizar um item.',
        );
    }

    public function show(int $productId): string|RedirectResponse
    {
        try {
            // Detalhe pode cotar um único produto; portfólio completo fica no botão explícito.
            $data = $this->service()->getProduct($productId);
            if ($data['intelligence'] === null && $this->request->getGet('quote') === '1') {
                $data = $this->service()->refreshProduct($productId, $this->auth()->id());
            }
        } catch (BusinessRuleException $e) {
            return redirect()->to('profitability/commercial')->with('error', $e->getMessage());
        }

        return view('profitability/commercial_show', [
            'title'       => 'Comercial · ' . ($data['product']['name'] ?? ('#' . $productId)),
            'data'        => $data,
            'breadcrumbs' => [
                'Rentabilidade' => 'profitability',
                'Inteligência comercial' => 'profitability/commercial',
                $data['product']['sku'] ?? 'Produto' => null,
            ],
            'cepPending'  => empty($data['offer']['origin_postal_code'] ?? null),
        ]);
    }

    public function refresh(int $productId): RedirectResponse
    {
        $back = (string) $this->request->getPost('redirect_to');
        if ($back !== 'list') {
            $back = 'profitability/commercial/' . $productId;
        } else {
            $back = 'profitability/commercial';
        }

        try {
            $this->service()->refreshProduct($productId, $this->auth()->id());
        } catch (BusinessRuleException $e) {
            return redirect()->to($back)->with('error', $e->getMessage());
        }

        return redirect()->to($back)->with(
            'success',
            'Simulação de preços e concorrência atualizadas (tarifas reconsultadas por preço-alvo).',
        );
    }

    public function addCompetitor(int $productId): RedirectResponse
    {
        try {
            (new \App\Services\Marketplaces\MercadoLivre\MercadoLivreCompetitionService())->addManual(
                $productId,
                (string) $this->request->getPost('title'),
                (string) $this->request->getPost('price'),
                $this->request->getPost('permalink') !== null ? (string) $this->request->getPost('permalink') : null,
                $this->request->getPost('note') !== null ? (string) $this->request->getPost('note') : null,
                $this->auth()->id(),
            );
            $this->service()->recomputeAfterManual($productId, $this->auth()->id());
        } catch (BusinessRuleException $e) {
            return redirect()->to('profitability/commercial/' . $productId)->with('error', $e->getMessage());
        }

        return redirect()->to('profitability/commercial/' . $productId)->with(
            'success',
            'Concorrente manual registrado com identificação da fonte.',
        );
    }

    public function ops(int $productId): string|RedirectResponse
    {
        try {
            $sim = $this->service()->monthlyOps($productId);
            $data = $this->service()->getProduct($productId);
        } catch (BusinessRuleException $e) {
            return redirect()->to('profitability/commercial/' . $productId)->with('error', $e->getMessage());
        }

        return view('profitability/commercial_ops', [
            'title'       => 'Operação mensal · ' . ($data['product']['sku'] ?? ('#' . $productId)),
            'sim'         => $sim,
            'data'        => $data,
            'volumes'     => MonthlyOpsSimulator::DEFAULT_VOLUMES,
            'breadcrumbs' => [
                'Inteligência comercial' => 'profitability/commercial',
                $data['product']['sku'] ?? 'Produto' => 'profitability/commercial/' . $productId,
                'Operação mensal' => null,
            ],
            'cepPending'  => empty($data['offer']['origin_postal_code'] ?? null),
        ]);
    }

    public function opsSimulate(int $productId): RedirectResponse|string
    {
        $price = trim((string) $this->request->getPost('sale_price'));
        $vols  = $this->request->getPost('volumes');
        $volumes = MonthlyOpsSimulator::DEFAULT_VOLUMES;
        if (is_string($vols) && trim($vols) !== '') {
            $parsed = array_values(array_filter(array_map(
                static fn ($v) => (int) trim((string) $v),
                explode(',', $vols),
            ), static fn (int $v) => $v > 0));
            if ($parsed !== []) {
                $volumes = $parsed;
            }
        }

        try {
            $sim  = $this->service()->monthlyOps($productId, $price !== '' ? $price : null, $volumes);
            $data = $this->service()->getProduct($productId);
        } catch (BusinessRuleException $e) {
            return redirect()->to('profitability/commercial/' . $productId . '/ops')->with('error', $e->getMessage());
        }

        return view('profitability/commercial_ops', [
            'title'       => 'Operação mensal · ' . ($data['product']['sku'] ?? ('#' . $productId)),
            'sim'         => $sim,
            'data'        => $data,
            'volumes'     => $volumes,
            'breadcrumbs' => [
                'Inteligência comercial' => 'profitability/commercial',
                $data['product']['sku'] ?? 'Produto' => 'profitability/commercial/' . $productId,
                'Operação mensal' => null,
            ],
            'cepPending'  => empty($data['offer']['origin_postal_code'] ?? null),
        ]);
    }

    private function service(): CommercialIntelligenceService
    {
        return service('commercialIntelligence');
    }
}

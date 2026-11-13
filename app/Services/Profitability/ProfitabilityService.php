<?php

namespace App\Services\Profitability;

use App\DTO\Profitability\CostLine;
use App\DTO\Profitability\ProfitabilityInput;
use App\DTO\Profitability\ProfitabilityResult;
use App\Exceptions\BusinessRuleException;
use App\Models\HomologationChecklistItemModel;
use App\Models\ProductModel;
use App\Models\ProfitabilityAnalysisHistoryModel;
use App\Models\ProfitabilityAnalysisModel;
use App\Models\SupplierOfferModel;
use App\Services\Audit\AuditService;
use App\Services\Settings\SettingsService;
use App\Services\Suppliers\DropifyImportService;
use App\Support\Decimal;

class ProfitabilityService
{
    public function __construct(
        private readonly ProfitabilityEngine $engine = new ProfitabilityEngine(),
        private readonly MercadoLivreCostReader $mlCosts = new MercadoLivreCostReader(),
        private readonly ProfitabilityAnalysisModel $analyses = new ProfitabilityAnalysisModel(),
        private readonly ProfitabilityAnalysisHistoryModel $history = new ProfitabilityAnalysisHistoryModel(),
        private readonly ProductModel $products = new ProductModel(),
        private readonly SupplierOfferModel $offers = new SupplierOfferModel(),
        private readonly ?SettingsService $settings = null,
        private readonly ?AuditService $audit = null,
    ) {
    }

    public function enabled(): bool
    {
        return ($this->settings ?? service('settings'))->getBool('profitability.enabled');
    }

    public function analyzeProduct(int $productId, ?int $userId = null, string $source = 'manual', ?string $overridePrice = null): array
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new BusinessRuleException('Produto não encontrado.');
        }

        $offer = $this->offers
            ->where('product_id', $productId)
            ->orderBy('active', 'DESC')
            ->orderBy('id', 'DESC')
            ->first();

        $salePrice = $overridePrice
            ?? ($product['sale_price'] ?? null)
            ?? ($offer['suggested_price'] ?? null)
            ?? null;
        $salePrice = Decimal::normalize((string) ($salePrice ?? ''));
        $costPrice = Decimal::normalize((string) ($offer['cost_price'] ?? $product['extra_cost'] ?? '0'));

        if ($salePrice === null || bccomp($salePrice, '0', 2) <= 0) {
            $result = $this->waitingResult('Preço de venda ausente.', $costPrice ?? '0.00');
            return $this->persist($product, $offer, $result, [], $userId, $source);
        }

        if ($costPrice === null || bccomp($costPrice, '0', 2) <= 0) {
            $result = $this->waitingResult('Custo do fornecedor ausente.', '0.00', $salePrice);
            return $this->persist($product, $offer, $result, [], $userId, $source);
        }

        $context = $product + [
            'weight_grams' => $offer['weight_grams'] ?? null,
            'height_cm'    => $offer['height_cm'] ?? $product['height'] ?? null,
            'width_cm'     => $offer['width_cm'] ?? $product['width'] ?? null,
            'length_cm'    => $offer['length_cm'] ?? $product['length'] ?? null,
        ];

        $quotes = $this->mlCosts->quote($context, $salePrice);
        $fee    = $this->mlCosts->bestFee($quotes);
        $ship   = $quotes['shipping'];

        $settings = $this->settings ?? service('settings');
        $input    = new ProfitabilityInput(
            salePrice: $salePrice,
            costPrice: $costPrice,
            marketplaceFeeTotal: $fee['amount'],
            marketplaceFeeReliability: $fee['reliability'],
            marketplaceFeeReason: $fee['reason'],
            sellerShippingCost: $ship['amount'],
            sellerShippingReliability: $ship['reliability'],
            sellerShippingReason: $ship['reason'],
            minimumMarginPercentage: (string) $settings->get('profitability.minimum_margin_percentage'),
            returnsReservePercentage: (string) $settings->get('profitability.returns_reserve_percentage'),
            variableOpsPercentage: (string) $settings->get('profitability.variable_ops_percentage'),
            variableOpsFixed: (string) $settings->get('profitability.variable_ops_fixed'),
            taxPercentage: (string) $settings->get('profitability.tax_percentage'),
            taxApproved: $settings->getBool('profitability.tax_approved'),
            monthlyFixedCosts: $this->monthlyFixedCosts($settings),
            monthlyUnitsAssumption: (int) $settings->get('fixed_costs.monthly_units_assumption'),
        );

        $result = $this->engine->analyze($input);

        return $this->persist($product, $offer, $result, $quotes, $userId, $source, $fee);
    }

    /**
     * Simulação sem gravar (ou grava histórico se $persist=true).
     */
    public function simulate(int $productId, string $salePrice, bool $persist = false, ?int $userId = null): array
    {
        $price = Decimal::normalize($salePrice);
        if ($price === null || bccomp($price, '0', 2) <= 0) {
            throw new BusinessRuleException('Informe um preço de venda válido para simular.');
        }

        if ($persist) {
            return $this->analyzeProduct($productId, $userId, 'simulate', $price);
        }

        // Reusa o fluxo persistindo temporariamente? Melhor calcular e devolver array.
        $saved = $this->analyzeProduct($productId, $userId, 'simulate', $price);

        return $saved;
    }

    /**
     * Bloqueio centralizado para novas publicações de produtos Dropify.
     */
    public function assertPublishable(int $productId): void
    {
        $settings = $this->settings ?? service('settings');
        if (! $settings->getBool('profitability.block_unsafe_publish')) {
            return;
        }

        $product = $this->products->find($productId);
        if ($product === null) {
            throw new BusinessRuleException('Produto não encontrado.');
        }

        if (($product['source_provider'] ?? null) !== DropifyImportService::PROVIDER) {
            $offer = $this->offers->select('supplier_offers.*, suppliers.provider_code')
                ->join('suppliers', 'suppliers.id = supplier_offers.supplier_id')
                ->where('supplier_offers.product_id', $productId)
                ->where('suppliers.provider_code', DropifyImportService::PROVIDER)
                ->first();
            if ($offer === null) {
                return;
            }
        }

        $analysis = $this->analyses->where('product_id', $productId)->first();
        if ($analysis === null) {
            $analysis = $this->analyzeProduct($productId, null, 'publish_guard');
        }

        if (($analysis['classification'] ?? '') !== ProfitabilityResult::RENTAVEL) {
            $reasons = json_decode((string) ($analysis['block_reasons'] ?? '[]'), true) ?: [];
            $label   = ProfitabilityResult::LABELS[$analysis['classification']] ?? $analysis['classification'];

            throw new BusinessRuleException(
                'Publicação bloqueada (' . $label . '): ' . ($reasons !== [] ? implode(' ', $reasons) : 'margem ou dados insuficientes.'),
            );
        }

        if ($settings->getBool('profitability.require_logistics_checklist') && ! $this->logisticsChecklistReady()) {
            throw new BusinessRuleException(
                'Publicação bloqueada: o checklist de homologação logística/fiscal da Dropify ainda tem itens pendentes ou negados sem evidência.',
            );
        }
    }

    public function logisticsChecklistReady(): bool
    {
        $model = new HomologationChecklistItemModel();
        $items = $model->where('provider_code', DropifyImportService::PROVIDER)->findAll();
        if ($items === []) {
            return false;
        }

        foreach ($items as $item) {
            if ($item['status'] !== HomologationChecklistItemModel::STATUS_CONFIRMED) {
                return false;
            }
            if (trim((string) ($item['evidence_note'] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function topMargins(int $limit = 10): array
    {
        return $this->analyses->withProduct()
            ->where('profitability_analyses.classification', ProfitabilityResult::RENTAVEL)
            ->orderBy('profitability_analyses.contribution_margin_pct', 'DESC')
            ->findAll($limit);
    }

    private function monthlyFixedCosts(SettingsService $settings): string
    {
        $total = '0.00';
        foreach ([
            'fixed_costs.dropify_monthly',
            'fixed_costs.fiscal_issuer_monthly',
            'fixed_costs.certificate_a1_monthly',
            'fixed_costs.das_mei_monthly',
            'fixed_costs.other_monthly',
        ] as $key) {
            $total = bcadd($total, Decimal::round((string) $settings->get($key), 2), 2);
        }

        // VPS compartilhada: entra no total da empresa; para rateio "dropshipping"
        // ainda soma, mas o breakdown marca como compartilhada.
        $total = bcadd($total, Decimal::round((string) $settings->get('fixed_costs.vps_monthly'), 2), 2);

        return Decimal::round($total, 2);
    }

    private function waitingResult(string $reason, string $cost, string $sale = '0.00'): ProfitabilityResult
    {
        return new ProfitabilityResult(
            classification: ProfitabilityResult::AGUARDANDO_DADOS,
            salePrice: $sale,
            costPrice: $cost,
            marketplaceFeeTotal: null,
            marketplaceFeeReliability: CostLine::UNKNOWN,
            sellerShippingCost: null,
            sellerShippingReliability: CostLine::UNKNOWN,
            variableCostsTotal: '0.00',
            contributionMargin: '0.00',
            contributionMarginPct: '0.00',
            operatingResult: '0.00',
            minimumViablePrice: null,
            breakEvenUnits: null,
            costReliability: CostLine::UNKNOWN,
            lines: [new CostLine('info', 'Pendência', null, CostLine::UNKNOWN, $reason)],
            blockReasons: [$reason],
            unknownEssential: ['dados_basicos'],
            fixedCostPerUnit: '0.00',
            monthlyFixedCosts: '0.00',
        );
    }

    private function persist(
        array $product,
        ?array $offer,
        ProfitabilityResult $result,
        array $quotes,
        ?int $userId,
        string $source,
        array $fee = [],
    ): array {
        $payload = $result->toArray() + [
            'quotes' => [
                'category_id' => $quotes['category_id'] ?? null,
                'classic'     => $quotes['classic'] ?? null,
                'premium'     => $quotes['premium'] ?? null,
                'shipping'    => $quotes['shipping'] ?? null,
            ],
            'fixed_costs_note' => 'Custos fixos são parâmetros de planejamento (não vêm da API).',
            'vps_shared'       => ($this->settings ?? service('settings'))->getBool('fixed_costs.vps_shared'),
        ];

        $classicFee = is_array($quotes['classic'] ?? null) ? ($quotes['classic']['sale_fee_amount'] ?? null) : null;
        $premiumFee = is_array($quotes['premium'] ?? null) ? ($quotes['premium']['sale_fee_amount'] ?? null) : null;

        $row = [
            'product_id'              => (int) $product['id'],
            'supplier_id'             => $offer['supplier_id'] ?? null,
            'supplier_offer_id'       => $offer['id'] ?? null,
            'classification'          => $result->classification,
            'sale_price'              => $result->salePrice,
            'cost_price'              => $result->costPrice,
            'marketplace_fee_total'   => $result->marketplaceFeeTotal,
            'marketplace_fee_source'  => $result->marketplaceFeeReliability,
            'seller_shipping_cost'    => $result->sellerShippingCost,
            'seller_shipping_source'  => $result->sellerShippingReliability,
            'variable_costs_total'    => $result->variableCostsTotal,
            'contribution_margin'     => $result->contributionMargin,
            'contribution_margin_pct' => $result->contributionMarginPct,
            'operating_result'        => $result->operatingResult,
            'minimum_viable_price'    => $result->minimumViablePrice,
            'break_even_units'        => $result->breakEvenUnits,
            'classic_margin_pct'      => $this->marginForFee($result, $classicFee),
            'premium_margin_pct'      => $this->marginForFee($result, $premiumFee),
            'classic_fee_total'       => $classicFee,
            'premium_fee_total'       => $premiumFee,
            'preferred_listing_type'  => $fee['listing_type'] ?? null,
            'cost_reliability'        => $result->costReliability,
            'unknown_costs'           => json_encode($result->unknownEssential, JSON_UNESCAPED_UNICODE),
            'breakdown'               => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'block_reasons'           => json_encode($result->blockReasons, JSON_UNESCAPED_UNICODE),
            'marketplace_category_id' => $quotes['category_id'] ?? null,
            'quote_account_id'        => isset($quotes['account']['id']) ? (int) $quotes['account']['id'] : null,
            'quoted_at'               => date('Y-m-d H:i:s'),
            'calculation_source'      => $source,
        ];

        $existing = $this->analyses->where('product_id', (int) $product['id'])->first();
        if ($existing === null) {
            $this->analyses->insert($row);
            $analysisId = (int) $this->analyses->getInsertID();
        } else {
            $this->analyses->update((int) $existing['id'], $row);
            $analysisId = (int) $existing['id'];
        }

        $this->history->insert([
            'product_id'              => (int) $product['id'],
            'analysis_id'             => $analysisId,
            'classification'          => $result->classification,
            'sale_price'              => $result->salePrice,
            'contribution_margin_pct' => $result->contributionMarginPct,
            'cost_reliability'        => $result->costReliability,
            'calculation_source'      => $source,
            'snapshot'                => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_by'              => $userId,
            'created_at'              => date('Y-m-d H:i:s'),
        ]);

        ($this->audit ?? service('audit'))->log(
            'profitability_analyze',
            'product',
            (int) $product['id'],
            null,
            [
                'classification' => $result->classification,
                'margin_pct'     => $result->contributionMarginPct,
                'source'         => $source,
                'reliability'    => $result->costReliability,
            ],
            $userId,
        );

        return $this->analyses->withProduct()->where('profitability_analyses.id', $analysisId)->first()
            ?? ($row + ['id' => $analysisId]);
    }

    private function marginForFee(ProfitabilityResult $base, ?string $feeTotal): ?string
    {
        if ($feeTotal === null || in_array('tarifa_marketplace', $base->unknownEssential, true) || in_array('frete_vendedor', $base->unknownEssential, true)) {
            return null;
        }

        $settings = $this->settings ?? service('settings');
        $input    = new ProfitabilityInput(
            salePrice: $base->salePrice,
            costPrice: $base->costPrice,
            marketplaceFeeTotal: $feeTotal,
            marketplaceFeeReliability: CostLine::CONFIRMED,
            marketplaceFeeReason: 'Comparativo Clássico/Premium',
            sellerShippingCost: $base->sellerShippingCost,
            sellerShippingReliability: $base->sellerShippingReliability,
            sellerShippingReason: '',
            minimumMarginPercentage: (string) $settings->get('profitability.minimum_margin_percentage'),
            returnsReservePercentage: (string) $settings->get('profitability.returns_reserve_percentage'),
            variableOpsPercentage: (string) $settings->get('profitability.variable_ops_percentage'),
            variableOpsFixed: (string) $settings->get('profitability.variable_ops_fixed'),
            taxPercentage: (string) $settings->get('profitability.tax_percentage'),
            taxApproved: $settings->getBool('profitability.tax_approved'),
            monthlyFixedCosts: $this->monthlyFixedCosts($settings),
            monthlyUnitsAssumption: (int) $settings->get('fixed_costs.monthly_units_assumption'),
        );

        return $this->engine->analyze($input)->contributionMarginPct;
    }
}

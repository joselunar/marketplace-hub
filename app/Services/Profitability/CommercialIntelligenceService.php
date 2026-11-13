<?php

namespace App\Services\Profitability;

use App\Exceptions\BusinessRuleException;
use App\Models\CommercialIntelligenceModel;
use App\Models\CompetitionObservationModel;
use App\Models\ProductModel;
use App\Models\ProfitabilityAnalysisModel;
use App\Models\SupplierOfferModel;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreCompetitionService;
use App\Services\Settings\SettingsService;
use App\Services\Suppliers\DropifyImportService;
use App\Support\Decimal;

class CommercialIntelligenceService
{
    public function __construct(
        private readonly ProductModel $products = new ProductModel(),
        private readonly SupplierOfferModel $offers = new SupplierOfferModel(),
        private readonly ProfitabilityAnalysisModel $analyses = new ProfitabilityAnalysisModel(),
        private readonly CommercialIntelligenceModel $intelligence = new CommercialIntelligenceModel(),
        private readonly CompetitionObservationModel $observations = new CompetitionObservationModel(),
        private readonly CommercialPriceSimulator $simulator = new CommercialPriceSimulator(),
        private readonly MercadoLivreCompetitionService $competition = new MercadoLivreCompetitionService(),
        private readonly MonthlyOpsSimulator $ops = new MonthlyOpsSimulator(),
        private readonly ?ProfitabilityCategoryService $categories = null,
        private readonly ?SettingsService $settings = null,
        private readonly ?ProfitabilityService $profitability = null,
    ) {
    }

    /**
     * Produtos Dropify reais (SKU DFY-DY*) prioritários; fallback todos dropify.
     *
     * @return list<array<string, mixed>>
     */
    public function realDropifyProducts(): array
    {
        $rows = $this->products
            ->where('source_provider', DropifyImportService::PROVIDER)
            ->like('sku', 'DFY-DY', 'after')
            ->orderBy('id', 'ASC')
            ->findAll();

        if ($rows !== []) {
            return $rows;
        }

        return $this->products
            ->where('source_provider', DropifyImportService::PROVIDER)
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshProduct(int $productId, ?int $userId = null): array
    {
        @set_time_limit(180);

        $product = $this->products->find($productId);
        if ($product === null) {
            throw new BusinessRuleException('Produto não encontrado.');
        }

        $offer = $this->offers->where('product_id', $productId)->orderBy('active', 'DESC')->orderBy('id', 'DESC')->first();
        $cost  = Decimal::normalize((string) ($offer['cost_price'] ?? '0')) ?? '0.00';
        if (bccomp($cost, '0', 2) <= 0) {
            throw new BusinessRuleException('Produto sem custo de compra para simular.');
        }

        // Garante análise atualizada.
        ($this->profitability ?? service('profitability'))->analyzeProduct($productId, $userId, 'commercial_refresh');
        $analysis = $this->analyses->where('product_id', $productId)->first();

        $catSvc  = $this->categories ?? new ProfitabilityCategoryService();
        $account = $catSvc->resolveAccount();
        if ($account === null) {
            throw new BusinessRuleException('Nenhuma conta Mercado Livre conectada.');
        }

        $context = $product + [
            'weight_grams'      => $offer['weight_grams'] ?? null,
            'height_cm'         => $offer['height_cm'] ?? $product['height'] ?? null,
            'width_cm'          => $offer['width_cm'] ?? $product['width'] ?? null,
            'length_cm'         => $offer['length_cm'] ?? $product['length'] ?? null,
            'origin_uf'         => $offer['origin_uf'] ?? $product['origin_uf'] ?? null,
            'origin_postal_code' => $offer['origin_postal_code'] ?? null,
            'marketplace_category_id' => $product['marketplace_category_id'] ?? ($analysis['marketplace_category_id'] ?? null),
            'marketplace_category_source' => $product['marketplace_category_source'] ?? ProfitabilityCategoryService::SOURCE_CONFIRMED,
        ];

        $settings = $this->settingsSnapshot();
        $targets  = $this->simulator->simulateTargets(
            $context,
            $cost,
            $offer,
            $settings,
            CommercialPriceSimulator::DEFAULT_TARGETS,
            (int) $account['id'],
        );

        $competition = $this->enrichCompetition(
            $productId,
            $this->competition->analyze($account, $product, $offer, $userId),
        );
        $target20    = $this->findTarget($targets, '20.00');
        $score       = $this->score($product, $offer, $analysis ?? [], $targets, $competition, $target20);

        $payload = [
            'product_id'       => $productId,
            'targets_json'     => json_encode($targets, JSON_UNESCAPED_UNICODE),
            'competition_json' => json_encode($competition, JSON_UNESCAPED_UNICODE),
            'score_json'       => json_encode($score, JSON_UNESCAPED_UNICODE),
            'data_reliability' => $score['data_reliability'],
            'quoted_at'        => date('Y-m-d H:i:s'),
            'created_by'       => $userId,
        ];

        $existing = $this->intelligence->findForProduct($productId);
        if ($existing === null) {
            $this->intelligence->insert($payload);
        } else {
            $this->intelligence->update((int) $existing['id'], $payload);
        }

        return $this->getProduct($productId);
    }

    /**
     * Recompõe concorrência/score após registro manual, sem reconsultar tarifas.
     *
     * @return array<string, mixed>
     */
    public function recomputeAfterManual(int $productId, ?int $userId = null): array
    {
        $existing = $this->intelligence->findForProduct($productId);
        if ($existing === null) {
            return $this->refreshProduct($productId, $userId);
        }

        $product = $this->products->find($productId);
        if ($product === null) {
            throw new BusinessRuleException('Produto não encontrado.');
        }
        $offer    = $this->offers->where('product_id', $productId)->orderBy('id', 'DESC')->first();
        $analysis = $this->analyses->where('product_id', $productId)->first() ?? [];
        $targets  = json_decode((string) $existing['targets_json'], true) ?: [];
        $competition = json_decode((string) ($existing['competition_json'] ?? '{}'), true) ?: [];
        $competition = $this->enrichCompetition($productId, $competition);
        $target20 = $this->findTarget($targets, '20.00');
        $score    = $this->score($product, $offer, $analysis, $targets, $competition, $target20);

        $this->intelligence->update((int) $existing['id'], [
            'competition_json' => json_encode($competition, JSON_UNESCAPED_UNICODE),
            'score_json'       => json_encode($score, JSON_UNESCAPED_UNICODE),
            'data_reliability' => $score['data_reliability'],
            'created_by'       => $userId,
        ]);

        return $this->getProduct($productId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getProduct(int $productId): array
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new BusinessRuleException('Produto não encontrado.');
        }
        $offer      = $this->offers->where('product_id', $productId)->orderBy('id', 'DESC')->first();
        $analysis   = $this->analyses->where('product_id', $productId)->first();
        $intel      = $this->intelligence->findForProduct($productId);
        $targets    = $intel ? (json_decode((string) $intel['targets_json'], true) ?: []) : [];
        $competition= $intel ? (json_decode((string) $intel['competition_json'], true) ?: []) : [];
        $score      = $intel ? (json_decode((string) $intel['score_json'], true) ?: []) : [];

        $observations = $this->observations->latestForProduct($productId, 30);
        if ($competition !== []) {
            $competition = $this->enrichCompetition($productId, $competition, $observations);
        }

        return [
            'product'      => $product,
            'offer'        => $offer,
            'analysis'     => $analysis,
            'intelligence' => $intel,
            'targets'      => $targets,
            'competition'  => $competition,
            'score'        => $score,
            'observations' => $observations,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function portfolio(?int $userId = null, bool $refresh = false): array
    {
        if ($refresh) {
            @set_time_limit(600);
        }

        $rows = [];
        foreach ($this->realDropifyProducts() as $product) {
            $id = (int) $product['id'];
            if ($refresh) {
                try {
                    $rows[] = $this->refreshProduct($id, $userId);
                } catch (BusinessRuleException $e) {
                    $rows[] = [
                        'product' => $product,
                        'error'   => $e->getMessage(),
                        'score'   => ['total' => 0, 'label' => 'indisponível'],
                    ];
                }
                continue;
            }

            if ($this->intelligence->findForProduct($id) !== null) {
                $rows[] = $this->getProduct($id);
                continue;
            }

            $offer    = $this->offers->where('product_id', $id)->orderBy('id', 'DESC')->first();
            $analysis = $this->analyses->where('product_id', $id)->first();
            $rows[]   = [
                'product'      => $product,
                'offer'        => $offer,
                'analysis'     => $analysis,
                'intelligence' => null,
                'targets'      => [],
                'competition'  => [],
                'score'        => [
                    'total'            => 0,
                    'label'            => 'aguardando_atualizacao',
                    'proven_profit'    => false,
                    'data_reliability' => 'desconhecido',
                    'recommendation'   => 'Clique em Atualizar portfólio ou abra o detalhe para cotar preços e concorrência.',
                ],
                'observations' => [],
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $sa = (float) ($a['score']['total'] ?? -999);
            $sb = (float) ($b['score']['total'] ?? -999);

            return $sb <=> $sa;
        });

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function monthlyOps(int $productId, ?string $salePrice = null, array $volumes = MonthlyOpsSimulator::DEFAULT_VOLUMES): array
    {
        $data = $this->getProduct($productId);
        $analysis = $data['analysis'] ?? null;
        $offer = $data['offer'] ?? null;
        if ($analysis === null) {
            throw new BusinessRuleException('Analise o produto antes de simular a operação mensal.');
        }

        $price = Decimal::normalize((string) ($salePrice ?? $analysis['sale_price'] ?? '')) ?? '0.00';
        $cost  = Decimal::normalize((string) ($analysis['cost_price'] ?? '0')) ?? '0.00';
        $fee   = Decimal::normalize((string) ($analysis['marketplace_fee_total'] ?? '0')) ?? '0.00';
        $ship  = Decimal::normalize((string) ($analysis['seller_shipping_cost'] ?? '0')) ?? '0.00';

        // Se houver preço-alvo 20% na inteligência, usa como referência de anúncio.
        $target20 = $this->findTarget($data['targets'] ?? [], '20.00');
        if ($salePrice === null && is_array($target20) && ! empty($target20['sale_price'])) {
            $price = (string) $target20['sale_price'];
            $fee   = Decimal::normalize((string) ($target20['marketplace_fee_total'] ?? $fee)) ?? $fee;
            $ship  = Decimal::normalize((string) ($target20['seller_shipping_cost'] ?? $ship)) ?? $ship;
        }

        $settings = $this->settings ?? service('settings');
        $returnsPct = (string) $settings->get('profitability.returns_reserve_percentage');
        $opsPct = (string) $settings->get('profitability.variable_ops_percentage');
        $opsFixed = (string) $settings->get('profitability.variable_ops_fixed');
        $returns = Decimal::round(bcdiv(bcmul($price, $returnsPct, 4), '100', 4), 2);
        $ops = Decimal::round(bcadd(bcdiv(bcmul($price, $opsPct, 4), '100', 4), $opsFixed, 4), 2);
        $variable = Decimal::round(bcadd(bcadd(bcadd($cost, $fee, 2), $ship, 2), bcadd($returns, $ops, 2), 2), 2);
        $fixed = $this->monthlyFixed();

        $sim = $this->ops->simulate(
            $price,
            $variable,
            $fixed,
            $cost,
            $volumes,
            (string) $settings->get('mei.annual_revenue_limit'),
            (string) $settings->get('mei.alert_percentage'),
        );

        $sim['product'] = [
            'id'   => $productId,
            'sku'  => $data['product']['sku'] ?? null,
            'name' => $data['product']['name'] ?? null,
        ];
        $sim['reliability_note'] = 'Frete e demais estimativas seguem a última análise; resultado operacional não é lucro comprovado.';
        $sim['stock'] = $offer['stock_quantity'] ?? null;

        return $sim;
    }

    /**
     * Inclui observações manuais nas estatísticas quando a API não cobre.
     *
     * @param array<string, mixed> $competition
     * @param list<array<string, mixed>>|null $observations
     *
     * @return array<string, mixed>
     */
    private function enrichCompetition(int $productId, array $competition, ?array $observations = null): array
    {
        $observations ??= $this->observations->latestForProduct($productId, 40);
        $manual = [];
        foreach ($observations as $obs) {
            if (($obs['source'] ?? '') !== 'manual') {
                continue;
            }
            $price = Decimal::normalize((string) ($obs['external_price'] ?? ''));
            if ($price === null) {
                continue;
            }
            $manual[] = [
                'id'               => $obs['external_item_id'] ?? null,
                'title'            => $obs['external_title'] ?? '',
                'price'            => $price,
                'currency_id'      => $obs['currency_id'] ?? 'BRL',
                'free_shipping'    => $obs['free_shipping'],
                'permalink'        => $obs['permalink'] ?? $obs['source_url'] ?? null,
                'seller_nickname'  => $obs['seller_nickname'] ?? null,
                'match_type'       => 'manual',
                'equivalence_note' => $obs['equivalence_note'] ?? 'Registro manual',
                'source'           => 'manual',
            ];
        }

        if ($manual === []) {
            return $competition;
        }

        $items = array_values(array_merge($competition['items'] ?? [], $manual));
        $prices = [];
        foreach ($items as $item) {
            if (! empty($item['price'])) {
                $prices[] = (string) $item['price'];
            }
        }
        usort($prices, static fn (string $a, string $b): int => bccomp($a, $b, 2));

        $competition['items'] = $items;
        $competition['stats'] = $this->competition->stats($prices);
        $competition['manual_count'] = count($manual);
        $limitations = $competition['limitations'] ?? [];
        $limitations[] = 'Estatísticas incluem ' . count($manual) . ' registro(s) manual(is) com fonte informada pelo usuário.';
        $competition['limitations'] = array_values(array_unique($limitations));
        if (($competition['method'] ?? 'none') === 'none') {
            $competition['method'] = 'manual';
        }

        return $competition;
    }

    /**
     * @param list<array<string, mixed>> $targets
     */
    private function findTarget(array $targets, string $pct): ?array
    {
        foreach ($targets as $row) {
            if (isset($row['target_margin_pct']) && bccomp((string) $row['target_margin_pct'], $pct, 2) === 0) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed>|null $offer
     * @param array<string, mixed> $analysis
     * @param list<array<string, mixed>> $targets
     * @param array<string, mixed> $competition
     * @param array<string, mixed>|null $target20
     *
     * @return array<string, mixed>
     */
    private function score(array $product, ?array $offer, array $analysis, array $targets, array $competition, ?array $target20): array
    {
        $indicative = (float) ($analysis['indicative_margin_pct'] ?? 0);
        $marginPts = max(0, min(40, $indicative)); // 0–40

        $median = $competition['stats']['median'] ?? null;
        $price20 = $target20['sale_price'] ?? null;
        $competitivePts = 0;
        $competitiveLabel = 'sem base comparável';
        if ($median !== null && $price20 !== null) {
            $diff = (float) bcsub((string) $price20, (string) $median, 2);
            if ($diff <= 0) {
                $competitivePts = 25;
                $competitiveLabel = 'preço-alvo 20% ≤ mediana concorrente';
            } elseif ($diff <= (float) $median * 0.1) {
                $competitivePts = 15;
                $competitiveLabel = 'preço-alvo 20% até 10% acima da mediana';
            } elseif ($diff <= (float) $median * 0.25) {
                $competitivePts = 8;
                $competitiveLabel = 'preço-alvo 20% até 25% acima da mediana';
            } else {
                $competitivePts = 0;
                $competitiveLabel = 'preço-alvo 20% pouco competitivo vs mediana';
            }
        }

        $cost = (float) ($offer['cost_price'] ?? $analysis['cost_price'] ?? 0);
        $capitalPts = $cost <= 20 ? 10 : ($cost <= 50 ? 7 : ($cost <= 100 ? 4 : 2));

        $stock = (int) ($offer['stock_quantity'] ?? 0);
        $stockPts = $stock >= 30 ? 10 : ($stock >= 10 ? 6 : ($stock > 0 ? 3 : 0));

        $hasCep = ! empty($offer['origin_postal_code']);
        $logisticsPts = $hasCep ? 10 : 3;
        $logisticsLabel = $hasCep
            ? 'CEP de origem do fornecedor disponível'
            : 'CEP de expedição Dropify pendente — risco logístico';

        $name = mb_strtolower((string) ($product['name'] ?? ''));
        $seasonal = (bool) preg_match('/natal|noel|halloween|páscoa|pascoa|dia das mães|black friday/i', $name);
        $seasonPts = $seasonal ? 3 : 5; // sazonal = um pouco mais arriscado

        $feeOk = ($analysis['marketplace_fee_source'] ?? '') === 'confirmado';
        $shipRel = (string) ($analysis['seller_shipping_source'] ?? '');
        $reliabilityPts = ($feeOk ? 6 : 0) + ($shipRel === 'confirmado' ? 4 : ($shipRel === 'estimado' ? 2 : 0));
        $dataReliability = ($feeOk && $shipRel === 'confirmado')
            ? 'confirmado'
            : (($feeOk || $shipRel === 'estimado') ? 'estimado' : 'desconhecido');

        $total = (int) round($marginPts + $competitivePts + $capitalPts + $stockPts + $logisticsPts + $seasonPts + $reliabilityPts);
        $proven = ($analysis['classification'] ?? '') === 'rentavel';

        return [
            'total'            => $total,
            'label'            => $proven ? 'lucro_comprovado_parcial' : 'potencial_comercial',
            'proven_profit'    => $proven,
            'data_reliability' => $dataReliability,
            'breakdown'        => [
                'margin'       => ['points' => round($marginPts, 1), 'value' => $indicative],
                'competitive'  => ['points' => $competitivePts, 'label' => $competitiveLabel, 'median' => $median, 'price_20' => $price20],
                'capital'      => ['points' => $capitalPts, 'cost' => $cost],
                'stock'        => ['points' => $stockPts, 'stock' => $stock],
                'logistics'    => ['points' => $logisticsPts, 'label' => $logisticsLabel],
                'seasonality'  => ['points' => $seasonPts, 'seasonal' => $seasonal],
                'reliability'  => ['points' => $reliabilityPts, 'fee' => $analysis['marketplace_fee_source'] ?? null, 'shipping' => $shipRel],
            ],
            'recommendation'   => $this->recommendation($total, $proven, $competitivePts, $indicative, $seasonal),
        ];
    }

    private function recommendation(int $total, bool $proven, int $competitivePts, float $indicative, bool $seasonal): string
    {
        if ($proven) {
            return 'Custos essenciais confirmados e classificação rentável — candidato a venda com bloqueios restantes do checklist.';
        }
        if ($total >= 70 && $competitivePts >= 15 && $indicative >= 15) {
            return 'Alto potencial indicativo e preço-alvo competitivo — priorizar validação de CEP/frete real antes de contratar.';
        }
        if ($total >= 50 && $indicative >= 10) {
            return 'Potencial moderado — útil para teste se frete real for menor que a estimativa atual.';
        }
        if ($seasonal && $indicative > 0) {
            return 'Item sazonal com margem apertada/indicativa — só faz sentido com janela de demanda clara.';
        }
        if ($indicative < 0) {
            return 'Indicativa negativa no preço sugerido Dropify — só reconsiderar com preço de anúncio bem acima e frete confirmado.';
        }

        return 'Baixa prioridade comercial com os dados atuais.';
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsSnapshot(): array
    {
        $settings = $this->settings ?? service('settings');

        return [
            'returns'       => $settings->get('profitability.returns_reserve_percentage'),
            'ops_pct'       => $settings->get('profitability.variable_ops_percentage'),
            'ops_fixed'     => $settings->get('profitability.variable_ops_fixed'),
            'tax_pct'       => $settings->get('profitability.tax_percentage'),
            'tax_approved'  => $settings->getBool('profitability.tax_approved'),
            'fixed_monthly' => $this->monthlyFixed(),
            'units'         => (int) $settings->get('fixed_costs.monthly_units_assumption'),
        ];
    }

    private function monthlyFixed(): string
    {
        $settings = $this->settings ?? service('settings');
        $total = '0.00';
        foreach ([
            'fixed_costs.dropify_monthly',
            'fixed_costs.vps_monthly',
            'fixed_costs.fiscal_issuer_monthly',
            'fixed_costs.certificate_a1_monthly',
            'fixed_costs.das_mei_monthly',
            'fixed_costs.other_monthly',
        ] as $key) {
            $total = bcadd($total, Decimal::round((string) $settings->get($key), 2), 2);
        }

        return Decimal::round($total, 2);
    }
}

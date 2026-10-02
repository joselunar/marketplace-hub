<?php

namespace App\Services\Suppliers\Selection;

use App\DTO\Suppliers\OfferEvaluation;
use App\DTO\Suppliers\RankingConfig;
use App\DTO\Suppliers\SelectionResult;
use App\Enums\SupplierStatus;

/**
 * Cálculo do ranking de ofertas de um produto. Não acessa o banco: recebe
 * os dados prontos, o que permite testar as regras isoladamente.
 *
 * 1. Critérios eliminatórios (fornecedor inativo/bloqueado, oferta inativa ou
 *    bloqueada, custo inválido, sem estoque disponível, prazo acima do limite).
 * 2. Nota de 0 a 100 por critério entre as ofertas elegíveis.
 * 3. Score final = média das notas ponderada pelos pesos configurados.
 */
class SupplierRanker
{
    /**
     * Nota de confiabilidade quando o fornecedor não tem sincronizações na janela.
     */
    public const DEFAULT_RELIABILITY = 100.0;

    /**
     * @param array{id: int|string, safety_stock?: int|string|null} $product
     * @param list<array<string, mixed>>                            $offers      linhas de supplier_offers com os dados do fornecedor
     * @param array<int, array{score: float, total: int, errors: int, warnings: int}> $reliability por supplier_id
     */
    public function rank(array $product, array $offers, RankingConfig $config, array $reliability = []): SelectionResult
    {
        $prepared = array_map(fn (array $offer) => $this->prepare($product, $offer, $config), $offers);
        $eligible = array_values(array_filter($prepared, static fn (array $p) => $p['reasons'] === []));

        $minCost      = $this->minCost($eligible);
        $maxAvailable = $eligible === [] ? 0 : max(array_column($eligible, 'available'));

        $evaluations = [];

        foreach ($prepared as $item) {
            if ($item['reasons'] !== []) {
                $evaluations[] = $this->evaluation($item, [], null);

                continue;
            }

            $criteria = $this->criteria($item, $config, $minCost, $maxAvailable, $reliability[$item['supplier_id']] ?? null);
            $evaluations[] = $this->evaluation($item, $criteria, $this->weightedScore($criteria, $config));
        }

        usort($evaluations, $this->compare(...));

        $position = 1;

        foreach ($evaluations as $evaluation) {
            if ($evaluation->isEligible()) {
                $evaluation->rank = $position++;
            }
        }

        return new SelectionResult((int) $product['id'], $evaluations, $config);
    }

    /**
     * Estoque de segurança efetivo: produto > fornecedor > global.
     *
     * @return array{0: int, 1: string}
     */
    public function safetyStock(array $product, array $offer, RankingConfig $config): array
    {
        if (isset($product['safety_stock']) && $product['safety_stock'] !== '') {
            return [(int) $product['safety_stock'], OfferEvaluation::SAFETY_SOURCE_PRODUCT];
        }

        if (isset($offer['supplier_safety_stock']) && $offer['supplier_safety_stock'] !== '') {
            return [(int) $offer['supplier_safety_stock'], OfferEvaluation::SAFETY_SOURCE_SUPPLIER];
        }

        return [$config->globalSafetyStock, OfferEvaluation::SAFETY_SOURCE_GLOBAL];
    }

    private function prepare(array $product, array $offer, RankingConfig $config): array
    {
        $cost     = is_numeric($offer['cost_price'] ?? null) ? bcadd((string) $offer['cost_price'], '0', 2) : '0.00';
        $shipping = is_numeric($offer['shipping_cost'] ?? null) ? bcadd((string) $offer['shipping_cost'], '0', 2) : '0.00';
        $stock    = max(0, (int) ($offer['stock_quantity'] ?? 0));

        [$safety, $safetySource] = $this->safetyStock($product, $offer, $config);
        $available = max(0, $stock - $safety);

        $days = $offer['shipping_days'] ?? null;
        $days = ($days === null || $days === '') ? ($offer['default_shipping_days'] ?? null) : $days;
        $days = ($days === null || $days === '') ? null : (int) $days;

        $reasons = [];
        $status  = (string) ($offer['supplier_status'] ?? '');

        if ($status === SupplierStatus::Blocked->value) {
            $reasons[] = 'Fornecedor bloqueado';
        } elseif ($status !== SupplierStatus::Active->value) {
            $reasons[] = 'Fornecedor inativo';
        }

        if ((int) ($offer['active'] ?? 0) !== 1) {
            $reasons[] = 'Oferta inativa';
        }

        if ((int) ($offer['blocked'] ?? 0) === 1) {
            $blockedReason = trim((string) ($offer['blocked_reason'] ?? ''));
            $reasons[]     = 'Oferta bloqueada manualmente' . ($blockedReason !== '' ? ': ' . $blockedReason : '');
        }

        if (bccomp($cost, '0', 2) <= 0) {
            $reasons[] = 'Custo inválido';
        }

        if ($stock === 0) {
            $reasons[] = 'Sem estoque no fornecedor';
        } elseif ($available === 0) {
            $reasons[] = sprintf('Estoque de segurança: %d informado, %d reservado', $stock, $safety);
        }

        if ($days === null) {
            $reasons[] = 'Prazo de postagem não informado';
        } elseif ($days > $config->maxShippingDays) {
            $reasons[] = sprintf('Prazo de %d dias acima do limite de %d', $days, $config->maxShippingDays);
        }

        return [
            'offer_id'      => (int) $offer['id'],
            'supplier_id'   => (int) $offer['supplier_id'],
            'supplier_name' => (string) ($offer['supplier_name'] ?? ''),
            'supplier_sku'  => (string) ($offer['supplier_sku'] ?? ''),
            'priority'      => max(0, min(10, (int) ($offer['supplier_priority'] ?? 0))),
            'cost'          => $cost,
            'shipping'      => $shipping,
            'total'         => bcadd($cost, $shipping, 2),
            'stock'         => $stock,
            'safety'        => $safety,
            'safety_source' => $safetySource,
            'available'     => $available,
            'days'          => $days,
            'reasons'       => $reasons,
        ];
    }

    /**
     * @param array{score: float, total: int, errors: int, warnings: int}|null $reliability
     *
     * @return array<string, array{score: float, weight: float, rating: string, note: string}>
     */
    private function criteria(array $item, RankingConfig $config, string $minCost, int $maxAvailable, ?array $reliability): array
    {
        $scores = [
            // Menor custo total (custo + frete) recebe 100; os demais, proporcionalmente.
            'price' => [
                (float) bcdiv(bcmul($minCost, '100', 6), $item['total'], 6),
                sprintf('Custo + frete %s · menor entre elegíveis %s', $this->money($item['total']), $this->money($minCost)),
            ],
            // Proporcional ao maior estoque disponível entre as elegíveis.
            'stock' => [
                $maxAvailable > 0 ? $item['available'] / $maxAvailable * 100 : 0.0,
                sprintf('%d disponíveis · maior entre elegíveis %d', $item['available'], $maxAvailable),
            ],
            // Escala absoluta: postagem no mesmo dia = 100; cai linearmente até o limite.
            'shipping' => [
                ($config->maxShippingDays + 1 - $item['days']) / ($config->maxShippingDays + 1) * 100,
                sprintf('%d %s · limite %d', $item['days'], $item['days'] === 1 ? 'dia' : 'dias', $config->maxShippingDays),
            ],
            'reliability' => [
                $reliability['score'] ?? self::DEFAULT_RELIABILITY,
                $this->reliabilityNote($reliability, $config->reliabilityWindowDays),
            ],
            'priority' => [
                $item['priority'] * 10.0,
                sprintf('Prioridade %d de 10', $item['priority']),
            ],
        ];

        $criteria = [];

        foreach ($scores as $criterion => [$score, $note]) {
            $score = round(max(0.0, min(100.0, $score)), 2);

            $criteria[$criterion] = [
                'score'  => $score,
                'weight' => $config->weightShare($criterion),
                'rating' => OfferEvaluation::rating($score),
                'note'   => $note,
            ];
        }

        return $criteria;
    }

    private function weightedScore(array $criteria, RankingConfig $config): float
    {
        $sum = '0';

        foreach ($criteria as $criterion => $data) {
            $sum = bcadd($sum, bcmul(number_format($data['score'], 2, '.', ''), (string) $config->weights[$criterion], 6), 6);
        }

        return round((float) bcdiv($sum, $config->totalWeight(), 6), 2);
    }

    private function evaluation(array $item, array $criteria, ?float $score): OfferEvaluation
    {
        return new OfferEvaluation(
            offerId: $item['offer_id'],
            supplierId: $item['supplier_id'],
            supplierName: $item['supplier_name'],
            supplierSku: $item['supplier_sku'],
            costPrice: $item['cost'],
            shippingCost: $item['shipping'],
            totalCost: $item['total'],
            stockQuantity: $item['stock'],
            safetyStock: $item['safety'],
            safetySource: $item['safety_source'],
            availableStock: $item['available'],
            shippingDays: $item['days'],
            ineligibilityReasons: $item['reasons'],
            criteria: $criteria,
            score: $score,
        );
    }

    /**
     * Elegíveis primeiro, por score; empates resolvidos por menor custo total,
     * menor prazo, maior estoque disponível e, por fim, oferta mais antiga.
     */
    private function compare(OfferEvaluation $a, OfferEvaluation $b): int
    {
        if ($a->isEligible() !== $b->isEligible()) {
            return $a->isEligible() ? -1 : 1;
        }

        if ($a->isEligible()) {
            return [-$a->score, bccomp($a->totalCost, $b->totalCost, 2), $a->shippingDays, -$a->availableStock, $a->offerId]
                <=> [-$b->score, 0, $b->shippingDays, -$b->availableStock, $b->offerId];
        }

        return $a->offerId <=> $b->offerId;
    }

    private function minCost(array $eligible): string
    {
        $min = null;

        foreach ($eligible as $item) {
            if ($min === null || bccomp($item['total'], $min, 2) < 0) {
                $min = $item['total'];
            }
        }

        return $min ?? '0.00';
    }

    private function reliabilityNote(?array $reliability, int $windowDays): string
    {
        if ($reliability === null || $reliability['total'] === 0) {
            return sprintf('Sem falhas registradas nos últimos %d dias', $windowDays);
        }

        return sprintf(
            '%d %s e %d %s em %d sincronizações (%d dias)',
            $reliability['errors'],
            $reliability['errors'] === 1 ? 'falha' : 'falhas',
            $reliability['warnings'],
            $reliability['warnings'] === 1 ? 'alerta' : 'alertas',
            $reliability['total'],
            $windowDays,
        );
    }

    private function money(string $value): string
    {
        return 'R$ ' . number_format((float) $value, 2, ',', '.');
    }
}

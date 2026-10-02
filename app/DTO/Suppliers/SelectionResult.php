<?php

namespace App\DTO\Suppliers;

use App\DTO\Pricing\PricingContext;

/**
 * Ranking completo das ofertas de um produto: elegíveis em ordem de score,
 * seguidas das inelegíveis com o motivo.
 */
final class SelectionResult
{
    /**
     * @param list<OfferEvaluation> $evaluations
     */
    public function __construct(
        public readonly int $productId,
        public readonly array $evaluations,
        public readonly RankingConfig $config,
        public readonly ?PricingContext $pricing = null,
    ) {
    }

    public function primary(): ?OfferEvaluation
    {
        $first = $this->evaluations[0] ?? null;

        return $first !== null && $first->isEligible() ? $first : null;
    }

    /**
     * @return list<OfferEvaluation>
     */
    public function reserves(int $count = 2): array
    {
        return array_slice($this->eligible(), 1, $count);
    }

    /**
     * @return list<OfferEvaluation>
     */
    public function eligible(): array
    {
        return array_values(array_filter($this->evaluations, static fn (OfferEvaluation $e) => $e->isEligible()));
    }

    /**
     * @return list<OfferEvaluation>
     */
    public function ineligible(): array
    {
        return array_values(array_filter($this->evaluations, static fn (OfferEvaluation $e) => ! $e->isEligible()));
    }

    /**
     * Ofertas que seriam elegíveis se o preço permitisse a margem mínima,
     * da maior para a menor margem.
     *
     * @return list<OfferEvaluation>
     */
    public function blockedOnlyByMargin(): array
    {
        $offers = array_values(array_filter($this->evaluations, static fn (OfferEvaluation $e) => $e->blockedOnlyByMargin() && $e->pricing !== null));

        usort($offers, static fn (OfferEvaluation $a, OfferEvaluation $b) => bccomp($b->pricing->marginPercentage, $a->pricing->marginPercentage, 2));

        return $offers;
    }

    public function find(int $offerId): ?OfferEvaluation
    {
        foreach ($this->evaluations as $evaluation) {
            if ($evaluation->offerId === $offerId) {
                return $evaluation;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        $primary = $this->primary();

        return [
            'product_id'        => $this->productId,
            'selected_offer_id' => $primary?->offerId,
            'reserve_offer_ids' => array_map(static fn (OfferEvaluation $e) => $e->offerId, $this->reserves()),
            'weights'           => array_map(fn (string $c) => $this->config->weightShare($c), array_combine(array_keys(RankingConfig::CRITERIA), array_keys(RankingConfig::CRITERIA))),
            'margins'           => $this->pricing?->margins->toArray(),
            'manual_price'      => $this->pricing?->manualPrice,
            'offers'            => array_map(static fn (OfferEvaluation $e) => $e->toArray(), $this->evaluations),
        ];
    }
}

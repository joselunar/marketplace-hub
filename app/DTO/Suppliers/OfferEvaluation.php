<?php

namespace App\DTO\Suppliers;

use App\DTO\Pricing\PriceBreakdown;

/**
 * Resultado da avaliação de uma oferta pelo motor de seleção.
 */
final class OfferEvaluation
{
    public const SAFETY_SOURCE_PRODUCT  = 'product';
    public const SAFETY_SOURCE_SUPPLIER = 'supplier';
    public const SAFETY_SOURCE_GLOBAL   = 'global';

    /**
     * Posição no ranking (1 = principal). Null para ofertas inelegíveis.
     */
    public ?int $rank = null;

    /**
     * @param list<string>                                                                $ineligibilityReasons
     * @param array<string, array{score: float, weight: float, rating: string, note: string}> $criteria
     */
    public function __construct(
        public readonly int $offerId,
        public readonly int $supplierId,
        public readonly string $supplierName,
        public readonly string $supplierSku,
        public readonly string $costPrice,
        public readonly string $shippingCost,
        public readonly string $totalCost,
        public readonly int $stockQuantity,
        public readonly int $safetyStock,
        public readonly string $safetySource,
        public readonly int $availableStock,
        public readonly ?int $shippingDays,
        public readonly array $ineligibilityReasons,
        public readonly array $criteria = [],
        public readonly ?float $score = null,
        public readonly ?PriceBreakdown $pricing = null,
        public readonly bool $marginBlocked = false,
    ) {
    }

    public function isEligible(): bool
    {
        return $this->ineligibilityReasons === [];
    }

    /**
     * A oferta só ficou de fora por não atingir a margem mínima.
     */
    public function blockedOnlyByMargin(): bool
    {
        return $this->marginBlocked && count($this->ineligibilityReasons) === 1;
    }

    public function positionLabel(): string
    {
        return match (true) {
            $this->rank === null => 'Inelegível',
            $this->rank === 1    => 'Principal',
            $this->rank <= 3     => 'Reserva #' . ($this->rank - 1),
            default              => 'Elegível',
        };
    }

    public function safetySourceLabel(): string
    {
        return match ($this->safetySource) {
            self::SAFETY_SOURCE_PRODUCT  => 'definido no produto',
            self::SAFETY_SOURCE_SUPPLIER => 'definido no fornecedor',
            default                      => 'configuração global',
        };
    }

    /**
     * Classificação qualitativa de uma nota de 0 a 100.
     */
    public static function rating(float $score): string
    {
        return match (true) {
            $score >= 85 => 'excelente',
            $score >= 70 => 'bom',
            $score >= 50 => 'regular',
            default      => 'fraco',
        };
    }

    public function toArray(): array
    {
        return [
            'offer_id'              => $this->offerId,
            'supplier_id'           => $this->supplierId,
            'supplier_name'         => $this->supplierName,
            'supplier_sku'          => $this->supplierSku,
            'cost_price'            => $this->costPrice,
            'shipping_cost'         => $this->shippingCost,
            'total_cost'            => $this->totalCost,
            'stock_quantity'        => $this->stockQuantity,
            'safety_stock'          => $this->safetyStock,
            'safety_source'         => $this->safetySource,
            'available_stock'       => $this->availableStock,
            'shipping_days'         => $this->shippingDays,
            'eligible'              => $this->isEligible(),
            'ineligibility_reasons' => $this->ineligibilityReasons,
            'score'                 => $this->score,
            'rank'                  => $this->rank,
            'position'              => $this->positionLabel(),
            'criteria'              => $this->criteria,
            'pricing'               => $this->pricing?->toArray(),
        ];
    }
}

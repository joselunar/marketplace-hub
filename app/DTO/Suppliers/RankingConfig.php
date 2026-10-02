<?php

namespace App\DTO\Suppliers;

use App\Services\Settings\SettingsService;
use InvalidArgumentException;

/**
 * Parâmetros do ranking de fornecedores, lidos das configurações do sistema.
 */
final class RankingConfig
{
    /**
     * Critérios do score, na ordem em que aparecem na tela.
     */
    public const CRITERIA = [
        'price'       => 'Preço final',
        'stock'       => 'Estoque disponível',
        'shipping'    => 'Prazo de postagem',
        'reliability' => 'Confiabilidade',
        'priority'    => 'Prioridade do fornecedor',
    ];

    /**
     * @param array<string, string> $weights critério => peso (decimal em string)
     */
    public function __construct(
        public readonly array $weights,
        public readonly int $globalSafetyStock,
        public readonly int $maxShippingDays,
        public readonly int $reliabilityWindowDays = 30,
    ) {
        foreach (array_keys(self::CRITERIA) as $criterion) {
            if (! isset($weights[$criterion]) || ! is_numeric($weights[$criterion]) || bccomp($weights[$criterion], '0', 4) < 0) {
                throw new InvalidArgumentException("Peso inválido para o critério {$criterion}.");
            }
        }

        if (bccomp($this->totalWeight(), '0', 4) <= 0) {
            throw new InvalidArgumentException('A soma dos pesos do ranking precisa ser maior que zero.');
        }

        if ($globalSafetyStock < 0 || $maxShippingDays < 1 || $reliabilityWindowDays < 1) {
            throw new InvalidArgumentException('Parâmetros de estoque, prazo ou janela de confiabilidade inválidos.');
        }
    }

    public static function fromSettings(SettingsService $settings): self
    {
        $weights = [];

        foreach (array_keys(self::CRITERIA) as $criterion) {
            $weights[$criterion] = $settings->getDecimal('ranking.weight_' . $criterion);
        }

        return new self(
            $weights,
            $settings->getInt('stock.safety_quantity'),
            $settings->getInt('suppliers.max_shipping_days'),
            $settings->getInt('suppliers.reliability_window_days', 30),
        );
    }

    public function totalWeight(): string
    {
        $total = '0';

        foreach ($this->weights as $weight) {
            $total = bcadd($total, (string) $weight, 4);
        }

        return $total;
    }

    /**
     * Peso do critério em percentual do total (para exibição).
     */
    public function weightShare(string $criterion): float
    {
        return round((float) bcdiv(bcmul((string) $this->weights[$criterion], '100', 4), $this->totalWeight(), 4), 1);
    }
}

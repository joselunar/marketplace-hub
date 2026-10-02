<?php

namespace App\DTO\Pricing;

use App\Services\Settings\SettingsService;
use InvalidArgumentException;

/**
 * Parâmetros globais de precificação, lidos das configurações do sistema.
 *
 * Percentuais são aplicados sobre o preço de venda; valores fixos são por unidade.
 */
final class PricingConfig
{
    public const ROUNDING_CENTS     = 'cents';
    public const ROUNDING_ENDING_90 = 'ending_90';
    public const ROUNDING_ENDING_99 = 'ending_99';
    public const ROUNDING_INTEGER   = 'integer';

    public const ROUNDING_OPTIONS = [
        self::ROUNDING_CENTS     => 'Sem arredondamento (centavo acima)',
        self::ROUNDING_ENDING_90 => 'Terminar em ,90',
        self::ROUNDING_ENDING_99 => 'Terminar em ,99',
        self::ROUNDING_INTEGER   => 'Real inteiro acima',
    ];

    public function __construct(
        public readonly string $desiredMargin,
        public readonly string $minimumMargin,
        public readonly string $taxPercentage,
        public readonly string $marketplaceFeePercentage,
        public readonly string $marketplaceFixedFee,
        public readonly string $fixedFeeThreshold,
        public readonly string $extraCostPercentage,
        public readonly string $extraFixedCost,
        public readonly string $rounding = self::ROUNDING_CENTS,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if ($name !== 'rounding' && (! is_numeric($value) || bccomp($value, '0', 2) < 0)) {
                throw new InvalidArgumentException("Parâmetro de precificação inválido: {$name}.");
            }
        }

        if (! array_key_exists($rounding, self::ROUNDING_OPTIONS)) {
            throw new InvalidArgumentException("Regra de arredondamento desconhecida: {$rounding}.");
        }
    }

    public static function fromSettings(SettingsService $settings): self
    {
        return new self(
            desiredMargin: $settings->getDecimal('pricing.desired_margin_percentage'),
            minimumMargin: $settings->getDecimal('pricing.minimum_margin_percentage'),
            taxPercentage: $settings->getDecimal('pricing.estimated_tax_percentage'),
            marketplaceFeePercentage: $settings->getDecimal('pricing.marketplace_fee_percentage'),
            marketplaceFixedFee: $settings->getDecimal('pricing.marketplace_fixed_fee'),
            fixedFeeThreshold: $settings->getDecimal('pricing.marketplace_fixed_fee_threshold'),
            extraCostPercentage: $settings->getDecimal('pricing.extra_cost_percentage'),
            extraFixedCost: $settings->getDecimal('pricing.extra_fixed_cost'),
            rounding: (string) $settings->get('pricing.rounding', self::ROUNDING_CENTS),
        );
    }

    /**
     * Mesma configuração com a comissão e a tarifa fixa informadas pelo
     * marketplace para um preço específico. A tarifa fixa já vem calculada
     * para aquele preço, então deixa de depender da faixa configurada.
     */
    public function withMarketplaceFees(string $percentage, string $fixedFee): self
    {
        return new self(
            desiredMargin: $this->desiredMargin,
            minimumMargin: $this->minimumMargin,
            taxPercentage: $this->taxPercentage,
            marketplaceFeePercentage: $percentage,
            marketplaceFixedFee: $fixedFee,
            fixedFeeThreshold: '0.00',
            extraCostPercentage: $this->extraCostPercentage,
            extraFixedCost: $this->extraFixedCost,
            rounding: $this->rounding,
        );
    }

    /**
     * Mesma configuração com as tarifas padrão de um marketplace. Valores
     * nulos mantêm os globais, então cada campo pode ser sobrescrito sozinho.
     *
     * @param array{fee_percentage?: string|null, fixed_fee?: string|null, fixed_fee_threshold?: string|null} $marketplace
     */
    public function withMarketplaceDefaults(array $marketplace): self
    {
        $pick = static fn (string $key, string $fallback): string => isset($marketplace[$key]) && $marketplace[$key] !== '' ? (string) $marketplace[$key] : $fallback;

        return new self(
            desiredMargin: $this->desiredMargin,
            minimumMargin: $this->minimumMargin,
            taxPercentage: $this->taxPercentage,
            marketplaceFeePercentage: $pick('fee_percentage', $this->marketplaceFeePercentage),
            marketplaceFixedFee: $pick('fixed_fee', $this->marketplaceFixedFee),
            fixedFeeThreshold: $pick('fixed_fee_threshold', $this->fixedFeeThreshold),
            extraCostPercentage: $this->extraCostPercentage,
            extraFixedCost: $this->extraFixedCost,
            rounding: $this->rounding,
        );
    }

    /**
     * Soma dos percentuais cobrados sobre o preço, sem a margem.
     */
    public function percentageCosts(): string
    {
        return bcadd(bcadd($this->marketplaceFeePercentage, $this->taxPercentage, 2), $this->extraCostPercentage, 2);
    }

    public function roundingLabel(): string
    {
        return self::ROUNDING_OPTIONS[$this->rounding];
    }
}

<?php

namespace App\DTO\Pricing;

/**
 * Margens efetivas de um produto e de onde cada uma veio
 * (produto > categoria, subindo pelas categorias pai > configuração global).
 */
final class MarginPolicy
{
    public const SOURCE_PRODUCT  = 'product';
    public const SOURCE_CATEGORY = 'category';
    public const SOURCE_GLOBAL   = 'global';

    public function __construct(
        public readonly string $desired,
        public readonly string $desiredSource,
        public readonly string $minimum,
        public readonly string $minimumSource,
        public readonly ?string $desiredCategory = null,
        public readonly ?string $minimumCategory = null,
    ) {
    }

    public static function global(PricingConfig $config): self
    {
        return new self($config->desiredMargin, self::SOURCE_GLOBAL, $config->minimumMargin, self::SOURCE_GLOBAL);
    }

    /**
     * Margem usada no preço sugerido. Se a desejada herdada ficar abaixo da
     * mínima (ex.: mínima definida no produto), vale a mínima.
     */
    public function target(): string
    {
        return bccomp($this->minimum, $this->desired, 2) > 0 ? $this->minimum : $this->desired;
    }

    public function desiredSourceLabel(): string
    {
        return $this->label($this->desiredSource, $this->desiredCategory);
    }

    public function minimumSourceLabel(): string
    {
        return $this->label($this->minimumSource, $this->minimumCategory);
    }

    public function toArray(): array
    {
        return [
            'desired'        => $this->desired,
            'desired_source' => $this->desiredSource,
            'desired_label'  => $this->desiredSourceLabel(),
            'minimum'        => $this->minimum,
            'minimum_source' => $this->minimumSource,
            'minimum_label'  => $this->minimumSourceLabel(),
            'target'         => $this->target(),
        ];
    }

    private function label(string $source, ?string $category): string
    {
        return match ($source) {
            self::SOURCE_PRODUCT  => 'definida no produto',
            self::SOURCE_CATEGORY => 'categoria ' . $category,
            default               => 'configuração global',
        };
    }
}

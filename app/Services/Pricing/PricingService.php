<?php

namespace App\Services\Pricing;

use App\DTO\Pricing\CostInput;
use App\DTO\Pricing\MarginPolicy;
use App\DTO\Pricing\PriceBreakdown;
use App\DTO\Pricing\PricingConfig;
use App\DTO\Pricing\PricingContext;
use App\Exceptions\ValidationException;
use App\Models\CategoryModel;
use App\Models\MarketplaceModel;
use App\Services\Settings\SettingsService;
use App\Support\Decimal;

/**
 * Precificação: resolve as margens de cada produto (produto > categoria >
 * global) e monta o contexto usado pelo calculador e pelo motor de seleção.
 */
class PricingService
{
    public function __construct(
        private readonly PriceCalculator $calculator = new PriceCalculator(),
        private readonly CategoryModel $categories = new CategoryModel(),
        private readonly ?SettingsService $settings = null,
        private readonly MarketplaceModel $marketplaces = new MarketplaceModel(),
    ) {
    }

    /**
     * Parâmetros globais ou, com o slug, já com as tarifas padrão do marketplace.
     */
    public function config(?string $marketplace = null): PricingConfig
    {
        $config = PricingConfig::fromSettings($this->settings ?? service('settings'));
        if ($marketplace === null) {
            return $config;
        }

        $row = $this->marketplaces->findBySlug($marketplace);

        return $row === null ? $config : $config->withMarketplaceDefaults($row);
    }

    public function configForMarketplaceId(?int $marketplaceId): PricingConfig
    {
        $config = PricingConfig::fromSettings($this->settings ?? service('settings'));
        $row    = $marketplaceId === null ? null : $this->marketplaces->find($marketplaceId);

        return $row === null ? $config : $config->withMarketplaceDefaults($row);
    }

    public function calculator(): PriceCalculator
    {
        return $this->calculator;
    }

    /**
     * @param array{category_id?: int|string|null, desired_margin_percentage?: string|null, minimum_margin_percentage?: string|null} $product
     */
    public function marginPolicy(array $product, ?PricingConfig $config = null): MarginPolicy
    {
        return $this->resolveMargins($product, $config ?? $this->config(), $this->categoryTree());
    }

    public function contextFor(array $product, ?PricingConfig $config = null): PricingContext
    {
        $config ??= $this->config();

        return $this->buildContext($product, $config, $this->categoryTree());
    }

    /**
     * Contextos de vários produtos com uma única leitura das categorias.
     *
     * @param list<array<string, mixed>> $products
     *
     * @return array<int, PricingContext> por id do produto
     */
    public function contexts(array $products, ?PricingConfig $config = null): array
    {
        $config ??= $this->config();
        $tree     = $this->categoryTree();
        $contexts = [];

        foreach ($products as $product) {
            $contexts[(int) $product['id']] = $this->buildContext($product, $config, $tree);
        }

        return $contexts;
    }

    /**
     * Simulação avulsa (tela de precificação e API). Campos vazios de margem
     * herdam da categoria informada ou da configuração global.
     *
     * @param array<string, mixed> $input
     */
    public function simulate(array $input): PriceBreakdown
    {
        $fields = [
            'cost_price'                => ['Custo do produto', true],
            'shipping_cost'             => ['Frete do fornecedor', false],
            'extra_cost'                => ['Custo extra do produto', false],
            'desired_margin_percentage' => ['Margem desejada', false],
            'minimum_margin_percentage' => ['Margem mínima', false],
            'sale_price'                => ['Preço de venda', false],
        ];

        $values = [];
        $errors = [];

        foreach ($fields as $field => [$label, $required]) {
            $raw = $input[$field] ?? null;
            $raw = is_string($raw) ? trim($raw) : $raw;

            if ($raw === null || $raw === '') {
                if ($required) {
                    $errors[$field] = "Informe o campo {$label}.";
                }
                $values[$field] = null;

                continue;
            }

            $value = Decimal::normalize($raw);

            if ($value === null || bccomp($value, '0', 2) < 0) {
                $errors[$field] = "{$label}: informe um valor numérico maior ou igual a zero.";

                continue;
            }

            if (str_ends_with($field, '_percentage') && bccomp($value, '100', 2) >= 0) {
                $errors[$field] = "{$label}: informe um percentual menor que 100.";

                continue;
            }

            $values[$field] = $value;
        }

        if (($values['cost_price'] ?? null) !== null && bccomp($values['cost_price'], '0', 2) <= 0) {
            $errors['cost_price'] = 'O custo do produto precisa ser maior que zero.';
        }

        if (($values['sale_price'] ?? null) !== null && bccomp($values['sale_price'], '0', 2) <= 0) {
            $errors['sale_price'] = 'O preço de venda precisa ser maior que zero.';
        }

        $categoryId = isset($input['category_id']) && ctype_digit((string) $input['category_id']) ? (int) $input['category_id'] : null;

        if ($categoryId !== null && $this->categories->find($categoryId) === null) {
            $errors['category_id'] = 'Categoria inválida.';
        }

        $marketplace = isset($input['marketplace']) && is_string($input['marketplace']) && $input['marketplace'] !== '' ? $input['marketplace'] : null;

        if ($marketplace !== null && ! service('marketplaces')->has($marketplace)) {
            $errors['marketplace'] = 'Marketplace inválido.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $config  = $this->config($marketplace);
        $margins = $this->marginPolicy([
            'category_id'               => $categoryId,
            'desired_margin_percentage' => $values['desired_margin_percentage'],
            'minimum_margin_percentage' => $values['minimum_margin_percentage'],
        ], $config);

        $costs = new CostInput($values['cost_price'], $values['shipping_cost'] ?? '0.00', $values['extra_cost'] ?? '0.00');

        try {
            return $values['sale_price'] !== null
                ? $this->calculator->at($values['sale_price'], $costs, $margins, $config)
                : $this->calculator->suggest($costs, $margins, $config);
        } catch (PricingException $e) {
            throw new ValidationException(['desired_margin_percentage' => $e->getMessage()]);
        }
    }

    private function buildContext(array $product, PricingConfig $config, array $tree): PricingContext
    {
        $manual = $product['sale_price'] ?? null;
        $extra  = $product['extra_cost'] ?? null;

        return new PricingContext(
            config: $config,
            margins: $this->resolveMargins($product, $config, $tree),
            manualPrice: $manual === null || $manual === '' ? null : Decimal::round((string) $manual, 2),
            productExtraCost: $extra === null || $extra === '' ? '0.00' : Decimal::round((string) $extra, 2),
        );
    }

    /**
     * @param array<int, array{parent_id: int|null, name: string, desired: string|null, minimum: string|null}> $tree
     */
    private function resolveMargins(array $product, PricingConfig $config, array $tree): MarginPolicy
    {
        $desired = [$config->desiredMargin, MarginPolicy::SOURCE_GLOBAL, null];
        $minimum = [$config->minimumMargin, MarginPolicy::SOURCE_GLOBAL, null];

        $categoryId = isset($product['category_id']) && $product['category_id'] !== '' ? (int) $product['category_id'] : null;
        $foundDesired = false;
        $foundMinimum = false;
        $guard        = 0;

        while ($categoryId !== null && isset($tree[$categoryId]) && $guard++ < 50) {
            $category = $tree[$categoryId];

            if (! $foundDesired && $category['desired'] !== null) {
                $desired      = [$category['desired'], MarginPolicy::SOURCE_CATEGORY, $category['name']];
                $foundDesired = true;
            }

            if (! $foundMinimum && $category['minimum'] !== null) {
                $minimum      = [$category['minimum'], MarginPolicy::SOURCE_CATEGORY, $category['name']];
                $foundMinimum = true;
            }

            $categoryId = $category['parent_id'];
        }

        if (($product['desired_margin_percentage'] ?? null) !== null && $product['desired_margin_percentage'] !== '') {
            $desired = [Decimal::round((string) $product['desired_margin_percentage'], 2), MarginPolicy::SOURCE_PRODUCT, null];
        }

        if (($product['minimum_margin_percentage'] ?? null) !== null && $product['minimum_margin_percentage'] !== '') {
            $minimum = [Decimal::round((string) $product['minimum_margin_percentage'], 2), MarginPolicy::SOURCE_PRODUCT, null];
        }

        return new MarginPolicy($desired[0], $desired[1], $minimum[0], $minimum[1], $desired[2], $minimum[2]);
    }

    /**
     * @return array<int, array{parent_id: int|null, name: string, desired: string|null, minimum: string|null}>
     */
    private function categoryTree(): array
    {
        $rows = $this->categories->builder()
            ->select('id, parent_id, name, desired_margin_percentage, minimum_margin_percentage')
            ->get()
            ->getResultArray();

        $tree = [];

        foreach ($rows as $row) {
            $tree[(int) $row['id']] = [
                'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'name'      => (string) $row['name'],
                'desired'   => $row['desired_margin_percentage'],
                'minimum'   => $row['minimum_margin_percentage'],
            ];
        }

        return $tree;
    }
}

<?php

namespace App\Services\Marketplaces\MercadoLivre;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use CodeIgniter\Cache\CacheInterface;

/**
 * Categorias, atributos e preditor de categoria do Mercado Livre.
 *
 * Recursos usados (documentação "Domínios e Categorias" e "Atributos"):
 * - GET /sites/{SITE_ID}/categories
 * - GET /categories/{CATEGORY_ID}
 * - GET /categories/{CATEGORY_ID}/attributes
 * - GET /sites/{SITE_ID}/domain_discovery/search?q=
 *
 * As respostas são reduzidas aos campos que o painel usa e guardadas em cache.
 */
class MercadoLivreCategoryService
{
    private const CATEGORY_ID_PATTERN = '/^[A-Z]{3}\d{1,12}$/';
    private const MAX_VALUES_SHOWN    = 25;

    private readonly MercadoLivreService $meli;
    private readonly CacheInterface $cache;

    public function __construct(?MercadoLivreService $meli = null, ?CacheInterface $cache = null)
    {
        $this->meli  = $meli ?? service('mercadoLivre');
        $this->cache = $cache ?? service('cache');
    }

    /**
     * Categorias de primeiro nível do site da conta.
     *
     * @return list<array{id: string, name: string}>
     */
    public function roots(array $account): array
    {
        $site = $this->meli->siteFor($account);

        return $this->remember('meli_roots_' . $site, function () use ($account, $site): array {
            $rows = $this->meli->get((int) $account['id'], '/sites/' . rawurlencode($site) . '/categories');

            return array_values(array_map(static fn (array $row): array => [
                'id'   => (string) ($row['id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
            ], array_filter($rows, 'is_array')));
        });
    }

    /**
     * Detalhes de uma categoria: caminho, filhas e regras de publicação.
     */
    public function category(array $account, string $categoryId): array
    {
        $categoryId = $this->assertCategoryId($categoryId);

        return $this->remember('meli_category_' . $categoryId, function () use ($account, $categoryId): array {
            $data     = $this->meli->get((int) $account['id'], '/categories/' . $categoryId);
            $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
            $children = array_values(array_filter($data['children_categories'] ?? [], 'is_array'));

            return [
                'id'          => (string) ($data['id'] ?? $categoryId),
                'name'        => (string) ($data['name'] ?? ''),
                'permalink'   => is_string($data['permalink'] ?? null) ? $data['permalink'] : null,
                'total_items' => isset($data['total_items_in_this_category']) ? (int) $data['total_items_in_this_category'] : null,
                'path'        => array_values(array_map(static fn (array $node): array => [
                    'id'   => (string) ($node['id'] ?? ''),
                    'name' => (string) ($node['name'] ?? ''),
                ], array_filter($data['path_from_root'] ?? [], 'is_array'))),
                'children'    => array_map(static fn (array $child): array => [
                    'id'          => (string) ($child['id'] ?? ''),
                    'name'        => (string) ($child['name'] ?? ''),
                    'total_items' => isset($child['total_items_in_this_category']) ? (int) $child['total_items_in_this_category'] : null,
                ], $children),
                'is_leaf'     => $children === [],
                'settings'    => [
                    'listing_allowed'       => isset($settings['listing_allowed']) ? (bool) $settings['listing_allowed'] : null,
                    'status'                => is_string($settings['status'] ?? null) ? $settings['status'] : null,
                    'max_title_length'      => isset($settings['max_title_length']) ? (int) $settings['max_title_length'] : null,
                    'max_pictures_per_item' => isset($settings['max_pictures_per_item']) ? (int) $settings['max_pictures_per_item'] : null,
                    'item_conditions'       => array_values(array_filter($settings['item_conditions'] ?? [], 'is_string')),
                    'buying_modes'          => array_values(array_filter($settings['buying_modes'] ?? [], 'is_string')),
                    'minimum_price'         => isset($settings['minimum_price']) ? (string) $settings['minimum_price'] : null,
                    'catalog_domain'        => is_string($settings['catalog_domain'] ?? null) ? $settings['catalog_domain'] : null,
                ],
            ];
        });
    }

    /**
     * Atributos da categoria, obrigatórios primeiro.
     *
     * @return list<array<string, mixed>>
     */
    public function attributes(array $account, string $categoryId): array
    {
        $categoryId = $this->assertCategoryId($categoryId);

        return $this->remember('meli_attributes_' . $categoryId, function () use ($account, $categoryId): array {
            $rows       = $this->meli->get((int) $account['id'], '/categories/' . $categoryId . '/attributes');
            $attributes = array_map($this->normalizeAttribute(...), array_values(array_filter($rows, 'is_array')));

            usort($attributes, static fn (array $a, array $b): int => [$b['required'], $a['name']] <=> [$a['required'], $b['name']]);

            return $attributes;
        });
    }

    /**
     * Sugestões de categoria a partir do título do produto. Não usa cache: o título muda a cada busca.
     *
     * @return list<array<string, mixed>>
     */
    public function predict(array $account, string $title, int $limit = 5): array
    {
        $title = trim($title);
        $len   = mb_strlen($title);

        if ($len < 3 || $len > 150) {
            throw new ValidationException(['q' => 'Informe um título entre 3 e 150 caracteres.']);
        }

        $site = $this->meli->siteFor($account);
        $rows = $this->meli->get((int) $account['id'], '/sites/' . rawurlencode($site) . '/domain_discovery/search', [
            'q'     => $title,
            'limit' => max(1, min(8, $limit)),
        ]);

        return array_values(array_map(static fn (array $row): array => [
            'domain_id'     => (string) ($row['domain_id'] ?? ''),
            'domain_name'   => (string) ($row['domain_name'] ?? ''),
            'category_id'   => (string) ($row['category_id'] ?? ''),
            'category_name' => (string) ($row['category_name'] ?? ''),
            'attributes'    => array_values(array_map(static fn (array $attr): array => [
                'id'         => (string) ($attr['id'] ?? ''),
                'name'       => (string) ($attr['name'] ?? ''),
                'value_name' => (string) ($attr['value_name'] ?? ''),
            ], array_filter($row['attributes'] ?? [], 'is_array'))),
        ], array_filter($rows, 'is_array')));
    }

    private function normalizeAttribute(array $row): array
    {
        // A API devolve tags como objeto ({"required": true}); alguns recursos usam lista.
        $tags = $row['tags'] ?? [];
        if (is_array($tags) && array_is_list($tags)) {
            $tags = array_fill_keys(array_filter($tags, 'is_string'), true);
        }
        $tags = is_array($tags) ? array_keys(array_filter($tags)) : [];

        $values = array_values(array_filter($row['values'] ?? [], 'is_array'));

        return [
            'id'               => (string) ($row['id'] ?? ''),
            'name'             => (string) ($row['name'] ?? ''),
            'value_type'       => (string) ($row['value_type'] ?? ''),
            'group'            => is_string($row['attribute_group_name'] ?? null) ? $row['attribute_group_name'] : null,
            'tags'             => $tags,
            'required'         => in_array('required', $tags, true) || in_array('catalog_required', $tags, true),
            'value_max_length' => isset($row['value_max_length']) ? (int) $row['value_max_length'] : null,
            'values_count'     => count($values),
            'values'           => array_map(static fn (array $value): string => (string) ($value['name'] ?? ''), array_slice($values, 0, self::MAX_VALUES_SHOWN)),
            'units'            => array_values(array_map(static fn (array $unit): string => (string) ($unit['name'] ?? $unit['id'] ?? ''), array_filter($row['allowed_units'] ?? [], 'is_array'))),
        ];
    }

    private function assertCategoryId(string $categoryId): string
    {
        $categoryId = strtoupper(trim($categoryId));

        if (! preg_match(self::CATEGORY_ID_PATTERN, $categoryId)) {
            throw new BusinessRuleException('ID de categoria do Mercado Livre inválido: use o formato MLB1234.');
        }

        return $categoryId;
    }

    /**
     * @template T
     *
     * @param callable(): T $loader
     *
     * @return T
     */
    private function remember(string $key, callable $loader): mixed
    {
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $value = $loader();
        $this->cache->save($key, $value, $this->meli->config()->categoryCacheSeconds);

        return $value;
    }
}

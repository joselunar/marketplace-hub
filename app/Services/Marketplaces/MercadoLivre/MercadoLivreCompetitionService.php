<?php

namespace App\Services\Marketplaces\MercadoLivre;

use App\Exceptions\BusinessRuleException;
use App\Models\CompetitionObservationModel;
use App\Support\Decimal;
use Throwable;

/**
 * Concorrência somente leitura via APIs oficiais.
 * Não inventa vendas/demanda; equivalência duvidosa fica marcada.
 */
class MercadoLivreCompetitionService
{
    public function __construct(
        private readonly ?MercadoLivreService $meli = null,
        private readonly CompetitionObservationModel $observations = new CompetitionObservationModel(),
    ) {
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed>|null $offer
     * @param array<string, mixed> $account
     *
     * @return array{
     *   method: string,
     *   items: list<array<string, mixed>>,
     *   stats: array{count: int, min: ?string, median: ?string, max: ?string},
     *   limitations: list<string>,
     *   catalog: ?array
     * }
     */
    public function analyze(array $account, array $product, ?array $offer = null, ?int $userId = null): array
    {
        $meli   = $this->meli ?? service('mercadoLivre');
        $site   = $meli->siteFor($account);
        $ean    = $this->ean($product, $offer);
        $limitations = [];
        $items  = [];
        $catalog = null;
        $method = 'none';

        if ($ean !== null) {
            try {
                $catalogSearch = $meli->get((int) $account['id'], '/products/search', [
                    'site_id'             => $site,
                    'status'              => 'active',
                    'product_identifier'  => $ean,
                ]);
                $results = array_values(array_filter($catalogSearch['results'] ?? [], 'is_array'));
                if ($results !== []) {
                    $catalog = [
                        'id'    => (string) ($results[0]['id'] ?? ''),
                        'name'  => (string) ($results[0]['name'] ?? ''),
                        'domain_id' => (string) ($results[0]['domain_id'] ?? ''),
                    ];
                    $method = 'product_identifier';
                } else {
                    $limitations[] = 'Nenhum produto de catálogo ativo para o EAN/GTIN informado.';
                }
            } catch (Throwable $e) {
                $limitations[] = 'Falha em /products/search por identificador: ' . $e->getMessage();
            }
        } else {
            $limitations[] = 'Produto sem EAN/GTIN — busca por identificador universal indisponível.';
        }

        // Busca pública de anúncios ativos (preços de mercado).
        $query = $this->searchQuery($product, $offer, $ean);
        try {
            $params = [
                'q'      => $query,
                'limit'  => 20,
                'offset' => 0,
            ];
            if (! empty($product['marketplace_category_id'])) {
                $params['category'] = $product['marketplace_category_id'];
            }
            $search = $meli->get((int) $account['id'], '/sites/' . rawurlencode($site) . '/search', $params);
            $rows   = array_values(array_filter($search['results'] ?? [], 'is_array'));
            foreach ($rows as $row) {
                $items[] = $this->normalizeListing($row, $method === 'product_identifier' ? 'search_with_gtin_context' : 'keyword_search');
            }
            if ($method === 'none' && $items !== []) {
                $method = 'keyword_search';
            }
            if ($items === []) {
                $limitations[] = 'Busca /sites/{site}/search não retornou anúncios comparáveis.';
            } else {
                $limitations[] = 'Equivalência dos anúncios da busca textual não é garantida — revise título/atributos antes de decidir.';
            }
            $limitations[] = 'A API de busca não expõe vendas/demanda confiável neste fluxo; esses dados não foram inventados.';
        } catch (Throwable $e) {
            $limitations[] = 'Falha em /sites/{site}/search: ' . $e->getMessage();
        }

        $now = date('Y-m-d H:i:s');
        foreach ($items as $item) {
            $this->observations->insert([
                'product_id'       => (int) $product['id'],
                'source'           => 'api',
                'match_type'       => $item['match_type'],
                'external_item_id' => $item['id'],
                'external_title'   => $item['title'],
                'external_price'   => $item['price'],
                'currency_id'      => $item['currency_id'],
                'free_shipping'    => $item['free_shipping'] ? 1 : 0,
                'permalink'        => $item['permalink'],
                'seller_nickname'  => $item['seller_nickname'],
                'equivalence_note' => $item['equivalence_note'],
                'source_url'       => $item['permalink'],
                'raw_payload'      => json_encode($item['raw'] ?? [], JSON_UNESCAPED_UNICODE),
                'captured_at'      => $now,
                'created_by'       => $userId,
            ]);
        }

        $prices = array_values(array_filter(array_map(
            static fn (array $i): ?string => $i['price'],
            $items,
        )));
        usort($prices, static fn (string $a, string $b): int => bccomp($a, $b, 2));

        return [
            'method'      => $method,
            'query'       => $query,
            'ean'         => $ean,
            'catalog'     => $catalog,
            'items'       => $items,
            'stats'       => $this->stats($prices),
            'limitations' => array_values(array_unique($limitations)),
            'captured_at' => $now,
        ];
    }

    /**
     * @return array{count: int, min: ?string, median: ?string, max: ?string}
     */
    public function stats(array $prices): array
    {
        $count = count($prices);
        if ($count === 0) {
            return ['count' => 0, 'min' => null, 'median' => null, 'max' => null];
        }

        $mid = intdiv($count, 2);
        $median = $count % 2 === 1
            ? $prices[$mid]
            : Decimal::round(bcdiv(bcadd($prices[$mid - 1], $prices[$mid], 4), '2', 4), 2);

        return [
            'count'  => $count,
            'min'    => $prices[0],
            'median' => $median,
            'max'    => $prices[$count - 1],
        ];
    }

    public function addManual(
        int $productId,
        string $title,
        string $price,
        ?string $permalink,
        ?string $note,
        ?int $userId,
    ): array {
        $price = Decimal::normalize($price);
        if ($price === null || bccomp($price, '0', 2) <= 0) {
            throw new BusinessRuleException('Informe um preço válido para o concorrente manual.');
        }

        $data = [
            'product_id'       => $productId,
            'source'           => 'manual',
            'match_type'       => 'manual',
            'external_item_id' => null,
            'external_title'   => mb_substr(trim($title), 0, 255),
            'external_price'   => $price,
            'currency_id'      => 'BRL',
            'free_shipping'    => null,
            'permalink'        => $permalink !== null && $permalink !== '' ? mb_substr($permalink, 0, 500) : null,
            'seller_nickname'  => null,
            'equivalence_note' => $note !== null ? mb_substr(trim($note), 0, 255) : 'Registro manual — equivalência declarada pelo usuário.',
            'source_url'       => $permalink,
            'raw_payload'      => null,
            'captured_at'      => date('Y-m-d H:i:s'),
            'created_by'       => $userId,
        ];
        $this->observations->insert($data);

        return $this->observations->find($this->observations->getInsertID());
    }

    private function ean(array $product, ?array $offer): ?string
    {
        foreach ([$product['ean'] ?? null, $offer['ean'] ?? null] as $value) {
            $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
            if (strlen($digits) >= 8 && strlen($digits) <= 14) {
                return $digits;
            }
        }

        return null;
    }

    private function searchQuery(array $product, ?array $offer, ?string $ean): string
    {
        $parts = array_filter([
            $product['brand'] ?? null,
            $product['name'] ?? null,
            $ean,
        ], static fn ($v) => is_string($v) && trim($v) !== '');

        $q = trim(implode(' ', $parts));
        if (mb_strlen($q) > 120) {
            $q = mb_substr($q, 0, 120);
        }

        return $q !== '' ? $q : 'produto';
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeListing(array $row, string $matchType): array
    {
        $price = isset($row['price']) && is_numeric($row['price'])
            ? Decimal::round((string) $row['price'], 2)
            : null;
        $shipping = is_array($row['shipping'] ?? null) ? $row['shipping'] : [];
        $seller   = is_array($row['seller'] ?? null) ? $row['seller'] : [];

        return [
            'id'               => (string) ($row['id'] ?? ''),
            'title'            => (string) ($row['title'] ?? ''),
            'price'            => $price,
            'currency_id'      => (string) ($row['currency_id'] ?? 'BRL'),
            'free_shipping'    => (bool) ($shipping['free_shipping'] ?? false),
            'permalink'        => is_string($row['permalink'] ?? null) ? $row['permalink'] : null,
            'seller_nickname'  => is_string($seller['nickname'] ?? null) ? $seller['nickname'] : null,
            'match_type'       => $matchType,
            'equivalence_note' => $matchType === 'keyword_search'
                ? 'Resultado de busca textual — pode não ser o mesmo produto.'
                : 'Resultado de busca com contexto de GTIN/catálogo — valide atributos.',
            'raw'              => [
                'id'       => $row['id'] ?? null,
                'price'    => $row['price'] ?? null,
                'category' => $row['category_id'] ?? null,
                'shipping' => $shipping,
            ],
        ];
    }
}

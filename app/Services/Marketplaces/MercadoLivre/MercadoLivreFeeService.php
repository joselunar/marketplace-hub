<?php

namespace App\Services\Marketplaces\MercadoLivre;

use App\DTO\MercadoLivre\ListingFee;
use App\DTO\Pricing\CostInput;
use App\DTO\Pricing\PriceBreakdown;
use App\DTO\Pricing\PricingContext;
use App\Services\Pricing\PriceCalculator;
use App\Services\Pricing\PricingException;
use App\Support\Decimal;
use CodeIgniter\Cache\CacheInterface;
use UnexpectedValueException;

/**
 * Tarifas e tipos de anúncio consultados na API oficial:
 * - GET /sites/{SITE_ID}/listing_prices (comissão e tarifa fixa por preço)
 * - GET /users/{USER_ID}/available_listing_types?category_id=
 *
 * Nenhuma tarifa do Mercado Livre fica no código: o preço do anúncio é
 * calculado com os valores devolvidos pela API para a categoria, o tipo de
 * anúncio e o próprio preço.
 */
class MercadoLivreFeeService
{
    /**
     * A tarifa fixa depende do preço; o preço depende da tarifa. Poucas
     * rodadas bastam para estabilizar (ou detectar a oscilação na faixa).
     */
    private const MAX_ROUNDS = 4;

    private readonly MercadoLivreService $meli;
    private readonly CacheInterface $cache;
    private readonly PriceCalculator $calculator;

    public function __construct(?MercadoLivreService $meli = null, ?CacheInterface $cache = null, ?PriceCalculator $calculator = null)
    {
        $this->meli       = $meli ?? service('mercadoLivre');
        $this->cache      = $cache ?? service('cache');
        $this->calculator = $calculator ?? new PriceCalculator();
    }

    public function quote(array $account, string $categoryId, string $listingTypeId, string $price, string $currencyId): ListingFee
    {
        $site   = $this->meli->siteFor($account);
        $config = $this->meli->config();
        $query  = array_filter([
            'price'           => Decimal::round($price, 2),
            'category_id'     => $categoryId,
            'listing_type_id' => $listingTypeId,
            'currency_id'     => $currencyId,
            'logistic_type'   => $config->logisticType,
            'shipping_mode'   => $config->shippingMode,
        ], static fn (string $value): bool => $value !== '');

        $key    = 'meli_fee_' . md5($site . '|' . http_build_query($query));
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return ListingFee::fromResponse($cached, $listingTypeId, $query['price']);
        }

        $response = $this->meli->get((int) $account['id'], '/sites/' . rawurlencode($site) . '/listing_prices', $query);

        try {
            $fee = ListingFee::fromResponse($response, $listingTypeId, $query['price']);
        } catch (UnexpectedValueException $e) {
            throw new MercadoLivreApiException($e->getMessage(), 200, 'invalid_response', $e);
        }

        $this->cache->save($key, $response, $config->feeCacheSeconds);

        return $fee;
    }

    /**
     * Tipos de anúncio que a conta pode usar na categoria.
     *
     * @return list<array{id: string, name: string, remaining_listings: int|null}>
     */
    public function listingTypes(array $account, string $categoryId): array
    {
        $key    = 'meli_listing_types_' . (int) $account['id'] . '_' . $categoryId;
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->meli->get(
            (int) $account['id'],
            '/users/' . rawurlencode((string) $account['external_user_id']) . '/available_listing_types',
            ['category_id' => $categoryId],
        );

        $types = array_values(array_map(static fn (array $row): array => [
            'id'                 => (string) ($row['id'] ?? ''),
            'name'               => (string) ($row['name'] ?? $row['id'] ?? ''),
            'remaining_listings' => isset($row['remaining_listings']) ? (int) $row['remaining_listings'] : null,
        ], array_filter($response['available'] ?? [], static fn ($row): bool => is_array($row) && is_string($row['id'] ?? null))));

        $this->cache->save($key, $types, $this->meli->config()->feeCacheSeconds);

        return $types;
    }

    /**
     * Preço do anúncio com a tarifa oficial.
     *
     * Parte do preço calculado com as taxas configuradas, consulta a tarifa
     * para ele e recalcula com a comissão/tarifa fixa devolvidas, até o preço
     * parar de mudar. Se oscilar (faixa da tarifa fixa), fica com o maior
     * preço visto, consultado de novo, para nunca ficar abaixo da margem.
     * Preço manual do produto é mantido e só tem a composição recalculada.
     *
     * @return array{breakdown: PriceBreakdown, fee: ListingFee}
     *
     * @throws PricingException quando nenhum preço cobre os custos
     */
    public function price(
        array $account,
        string $categoryId,
        string $listingTypeId,
        string $currencyId,
        CostInput $costs,
        PricingContext $context,
        string $startPrice,
    ): array {
        if ($context->hasManualPrice()) {
            $fee = $this->quote($account, $categoryId, $listingTypeId, $context->manualPrice, $currencyId);

            return ['breakdown' => $this->breakdownAt($context->manualPrice, $fee, $costs, $context), 'fee' => $fee];
        }

        $price = Decimal::round($startPrice, 2);
        $seen  = [$price];

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $fee       = $this->quote($account, $categoryId, $listingTypeId, $price, $currencyId);
            $config    = $context->config->withMarketplaceFees($fee->percentageFee, $fee->fixedFee);
            $breakdown = $this->calculator->suggest($costs, $context->margins, $config);

            if (bccomp($breakdown->price, $price, 2) === 0) {
                return ['breakdown' => $breakdown, 'fee' => $fee];
            }

            $price  = $breakdown->price;
            $seen[] = $price;
        }

        usort($seen, static fn (string $a, string $b): int => bccomp($b, $a, 2));
        $fee = $this->quote($account, $categoryId, $listingTypeId, $seen[0], $currencyId);

        return ['breakdown' => $this->breakdownAt($seen[0], $fee, $costs, $context), 'fee' => $fee];
    }

    /**
     * @throws PricingException
     */
    private function breakdownAt(string $price, ListingFee $fee, CostInput $costs, PricingContext $context): PriceBreakdown
    {
        return $this->calculator->at($price, $costs, $context->margins, $context->config->withMarketplaceFees($fee->percentageFee, $fee->fixedFee));
    }
}

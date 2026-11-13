<?php

namespace App\Services\Profitability;

use App\DTO\MercadoLivre\ListingFee;
use App\DTO\Profitability\CostLine;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreApiException;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreAuthService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreFeeService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreService;
use App\Support\Decimal;
use Throwable;

/**
 * Consulta somente leitura de tarifas ML. Frete do vendedor só é
 * "confirmado" quando a API devolve custo interpretável; caso contrário
 * fica DESCONHECIDO (nunca inventado).
 */
class MercadoLivreCostReader
{
    public const TYPE_CLASSIC = 'gold_special';
    public const TYPE_PREMIUM = 'gold_pro';

    public function __construct(
        private readonly ?MercadoLivreService $meli = null,
        private readonly ?MercadoLivreFeeService $fees = null,
    ) {
    }

    /**
     * @return array{account: ?array, category_id: ?string, classic: ?array, premium: ?array, shipping: array}
     */
    public function quote(array $product, string $salePrice, ?string $categoryId = null, ?int $accountId = null): array
    {
        $account = $this->resolveAccount($accountId);
        $categoryId ??= $this->resolveCategoryId($product, $account);

        $classic = null;
        $premium = null;

        if ($account !== null && $categoryId !== null) {
            $classic = $this->feeQuote($account, $categoryId, self::TYPE_CLASSIC, $salePrice);
            $premium = $this->feeQuote($account, $categoryId, self::TYPE_PREMIUM, $salePrice);
        }

        return [
            'account'     => $account,
            'category_id' => $categoryId,
            'classic'     => $classic,
            'premium'     => $premium,
            'shipping'    => $this->shippingQuote($account, $product, $salePrice, $categoryId),
        ];
    }

    /**
     * @return array{amount: ?string, reliability: string, reason: string}
     */
    public function bestFee(array $quotes): array
    {
        foreach (['classic', 'premium'] as $key) {
            $row = $quotes[$key] ?? null;
            if (is_array($row) && ($row['reliability'] ?? '') === CostLine::CONFIRMED) {
                return [
                    'amount'      => $row['sale_fee_amount'],
                    'reliability' => CostLine::CONFIRMED,
                    'reason'      => 'sale_fee_amount de GET /sites/{site}/listing_prices (' . $row['listing_type_id'] . '). Tarifa fixa não somada de novo.',
                    'listing_type'=> $row['listing_type_id'],
                    'details'     => $row,
                ];
            }
        }

        return [
            'amount'       => null,
            'reliability'  => CostLine::UNKNOWN,
            'reason'       => $quotes['account'] === null
                ? 'Nenhuma conta Mercado Livre conectada para cotar tarifas.'
                : (($quotes['category_id'] ?? null) === null
                    ? 'Categoria MLB não definida no produto. Informe category_id na prévia/mapeamento.'
                    : 'Falha ao consultar listing_prices para Clássico e Premium.'),
            'listing_type' => null,
            'details'      => null,
        ];
    }

    private function resolveAccount(?int $accountId): ?array
    {
        try {
            $meli = $this->meli ?? service('mercadoLivre');
            if ($accountId !== null) {
                return $meli->resolveAccount($accountId);
            }

            $accounts = $meli->accounts()->listFor(MercadoLivreAuthService::SLUG);
            foreach ($accounts as $account) {
                if (($account['status'] ?? '') === 'connected' && (int) ($account['active'] ?? 0) === 1) {
                    return $account;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function resolveCategoryId(array $product, ?array $account): ?string
    {
        if (is_string($product['marketplace_category_id'] ?? null) && $product['marketplace_category_id'] !== '') {
            return $product['marketplace_category_id'];
        }

        if ($account === null || empty($product['category_id'])) {
            return null;
        }

        $row = db_connect()->table('marketplace_category_mappings')
            ->where('marketplace_id', (int) $account['marketplace_id'])
            ->where('category_id', (int) $product['category_id'])
            ->get()
            ->getRowArray();

        return is_string($row['external_category_id'] ?? null) ? $row['external_category_id'] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function feeQuote(array $account, string $categoryId, string $listingTypeId, string $price): ?array
    {
        try {
            $fee = ($this->fees ?? new MercadoLivreFeeService())->quote(
                $account,
                $categoryId,
                $listingTypeId,
                Decimal::round($price, 2),
                'BRL',
            );

            return $this->feeToArray($fee);
        } catch (MercadoLivreApiException $e) {
            return [
                'listing_type_id'  => $listingTypeId,
                'sale_fee_amount'  => null,
                'reliability'      => CostLine::UNKNOWN,
                'reason'           => 'API listing_prices: ' . $e->userMessage(),
            ];
        } catch (Throwable $e) {
            return [
                'listing_type_id'  => $listingTypeId,
                'sale_fee_amount'  => null,
                'reliability'      => CostLine::UNKNOWN,
                'reason'           => 'Falha ao cotar tarifa: ' . $e->getMessage(),
            ];
        }
    }

    private function feeToArray(ListingFee $fee): array
    {
        return [
            'listing_type_id'    => $fee->listingTypeId,
            'listing_type_name'  => $fee->listingTypeName,
            'sale_fee_amount'    => $fee->saleFeeAmount,
            'percentage_fee'     => $fee->percentageFee,
            'fixed_fee'          => $fee->fixedFee,
            'listing_fee_amount' => $fee->listingFeeAmount,
            'reliability'        => CostLine::CONFIRMED,
            'reason'             => 'sale_fee_amount oficial; fixed_fee já incluso no total.',
        ];
    }

    /**
     * @return array{amount: ?string, reliability: string, reason: string, raw: mixed}
     */
    private function shippingQuote(?array $account, array $product, string $salePrice, ?string $categoryId): array
    {
        if ($account === null) {
            return [
                'amount'      => null,
                'reliability' => CostLine::UNKNOWN,
                'reason'      => 'Conta ML indisponível para cotar frete do vendedor.',
                'raw'         => null,
            ];
        }

        $weightGrams = $this->weightGrams($product);
        $dims        = [
            'height' => $this->dimCm($product, 'height'),
            'width'  => $this->dimCm($product, 'width'),
            'length' => $this->dimCm($product, 'length'),
        ];

        if ($weightGrams === null || in_array(null, $dims, true)) {
            return [
                'amount'      => null,
                'reliability' => CostLine::UNKNOWN,
                'reason'      => 'Peso e dimensões incompletos; sem eles a cotação de frete do vendedor não é confiável.',
                'raw'         => null,
            ];
        }

        $config = ($this->meli ?? service('mercadoLivre'))->config();
        if ($config->logisticType === '' || $config->shippingMode === '') {
            return [
                'amount'      => null,
                'reliability' => CostLine::UNKNOWN,
                'reason'      => 'Defina MELI_LOGISTIC_TYPE e MELI_SHIPPING_MODE no .env para cotar frete com os parâmetros da conta.',
                'raw'         => null,
            ];
        }

        // Endpoint oficial de simulação; a interpretação do custo do vendedor
        // depende da resposta. Se não houver list_cost/clear seller cost, fica desconhecido.
        try {
            $meli = $this->meli ?? service('mercadoLivre');
            $path = '/users/' . rawurlencode((string) $account['external_user_id']) . '/shipping_options/free';
            $query = array_filter([
                'dimensions'      => sprintf('%dx%dx%d,%d', (int) ceil((float) $dims['height']), (int) ceil((float) $dims['width']), (int) ceil((float) $dims['length']), $weightGrams),
                'item_price'      => Decimal::round($salePrice, 2),
                'listing_type_id' => self::TYPE_CLASSIC,
                'mode'            => $config->shippingMode,
                'logistic_type'   => $config->logisticType,
                'condition'       => 'new',
                'verbose'         => 'true',
            ], static fn ($v) => $v !== null && $v !== '');

            if ($categoryId !== null) {
                $query['category_id'] = $categoryId;
            }

            $response = $meli->get((int) $account['id'], $path, $query);
            $parsed   = $this->parseShippingCost($response);
            if ($parsed !== null) {
                return [
                    'amount'      => $parsed,
                    'reliability' => CostLine::CONFIRMED,
                    'reason'      => 'Custo do vendedor interpretado de /users/{id}/shipping_options/free.',
                    'raw'         => $this->summarizeShipping($response),
                ];
            }

            return [
                'amount'      => null,
                'reliability' => CostLine::UNKNOWN,
                'reason'      => 'A API de frete respondeu, mas não foi possível determinar com segurança a parcela paga pelo vendedor (frete_indeterminado).',
                'raw'         => $this->summarizeShipping($response),
            ];
        } catch (MercadoLivreApiException $e) {
            return [
                'amount'      => null,
                'reliability' => CostLine::UNKNOWN,
                'reason'      => 'Falha na cotação de frete: ' . $e->userMessage(),
                'raw'         => null,
            ];
        } catch (Throwable $e) {
            return [
                'amount'      => null,
                'reliability' => CostLine::UNKNOWN,
                'reason'      => 'Falha na cotação de frete: ' . $e->getMessage(),
                'raw'         => null,
            ];
        }
    }

    private function parseShippingCost(array $response): ?string
    {
        $candidates = [];
        $walk = static function ($node) use (&$walk, &$candidates): void {
            if (! is_array($node)) {
                return;
            }
            foreach (['list_cost', 'senders_cost', 'cost', 'price'] as $key) {
                if (isset($node[$key]) && (is_int($node[$key]) || is_float($node[$key]) || (is_string($node[$key]) && is_numeric($node[$key])))) {
                    // list_cost em centavos em algumas respostas ME; se >= 1000 e inteiro, tratar como centavos só quando houver currency hint — aqui só aceitamos valores já em reais tipicamente < 10000.
                    $candidates[] = (string) $node[$key];
                }
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($response);

        // Sem campo inequívoco de "custo do vendedor", não inventamos.
        // Aceita apenas se existir coverage.list_cost ou similar no nível conhecido.
        $coverage = $response['coverage'] ?? null;
        if (is_array($coverage) && isset($coverage['all_country']['list_cost']) && is_numeric($coverage['all_country']['list_cost'])) {
            return Decimal::round((string) $coverage['all_country']['list_cost'], 2);
        }

        if (isset($response['options']) && is_array($response['options'])) {
            foreach ($response['options'] as $option) {
                if (! is_array($option)) {
                    continue;
                }
                if (isset($option['list_cost']) && is_numeric($option['list_cost'])) {
                    return Decimal::round((string) $option['list_cost'], 2);
                }
            }
        }

        return null;
    }

    private function summarizeShipping(array $response): array
    {
        return [
            'keys'    => array_slice(array_keys($response), 0, 20),
            'options' => isset($response['options']) && is_array($response['options']) ? count($response['options']) : 0,
        ];
    }

    private function weightGrams(array $product): ?int
    {
        if (isset($product['weight_grams']) && is_numeric($product['weight_grams'])) {
            return (int) $product['weight_grams'];
        }
        if (isset($product['weight']) && is_numeric($product['weight']) && (float) $product['weight'] > 0) {
            return (int) round(((float) $product['weight']) * 1000);
        }

        return null;
    }

    private function dimCm(array $product, string $field): ?string
    {
        $offerKey = $field . '_cm';
        if (isset($product[$offerKey]) && is_numeric($product[$offerKey])) {
            return Decimal::round((string) $product[$offerKey], 2);
        }
        if (isset($product[$field]) && is_numeric($product[$field])) {
            return Decimal::round((string) $product[$field], 2);
        }

        return null;
    }
}

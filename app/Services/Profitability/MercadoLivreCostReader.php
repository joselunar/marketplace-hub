<?php

namespace App\Services\Profitability;

use App\DTO\MercadoLivre\ListingFee;
use App\DTO\Profitability\CostLine;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreApiException;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreAuthService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreFeeService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreLogisticsService;
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
        private readonly ?MercadoLivreLogisticsService $logistics = null,
        private readonly ?ProfitabilityCategoryService $categories = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $offer
     *
     * @return array{account: ?array, category_id: ?string, classic: ?array, premium: ?array, shipping: array, logistics: array, quoted_at: string}
     */
    public function quote(array $product, string $salePrice, ?string $categoryId = null, ?int $accountId = null, ?array $offer = null): array
    {
        $account  = $this->resolveAccount($accountId);
        $category = $this->categories ?? new ProfitabilityCategoryService();
        $categoryId ??= $category->resolvedCategoryId($product, $offer, $account);

        $logistics = $account !== null
            ? ($this->logistics ?? new MercadoLivreLogisticsService())->resolveQuoteContext($account)
            : [
                'shipping_mode' => null,
                'logistic_type' => null,
                'source'        => 'no_account',
                'ready'         => false,
                'reason'        => 'Conta ML indisponível.',
                'options'       => [],
                'preferences'   => null,
            ];

        $classic = null;
        $premium = null;
        $quotedAt = date('Y-m-d H:i:s');

        $billableWeight = $this->weightGrams($product);
        if ($account !== null && $categoryId !== null) {
            $classic = $this->feeQuote($account, $categoryId, self::TYPE_CLASSIC, $salePrice, $logistics, $quotedAt, $billableWeight);
            $premium = $this->feeQuote($account, $categoryId, self::TYPE_PREMIUM, $salePrice, $logistics, $quotedAt, $billableWeight);
        }

        return [
            'account'     => $account,
            'category_id' => $categoryId,
            'classic'     => $classic,
            'premium'     => $premium,
            'shipping'    => $this->shippingQuote($account, $product, $salePrice, $categoryId, $logistics),
            'logistics'   => [
                'shipping_mode' => $logistics['shipping_mode'] ?? null,
                'logistic_type' => $logistics['logistic_type'] ?? null,
                'source'        => $logistics['source'] ?? null,
                'ready'         => (bool) ($logistics['ready'] ?? false),
                'reason'        => $logistics['reason'] ?? null,
                'options'       => $logistics['options'] ?? [],
            ],
            'quoted_at'   => $quotedAt,
        ];
    }

    /**
     * @return array{amount: ?string, reliability: string, reason: string, listing_type?: ?string, details?: mixed}
     */
    public function bestFee(array $quotes): array
    {
        foreach ([CostLine::CONFIRMED, CostLine::ESTIMATED] as $wanted) {
            foreach (['classic', 'premium'] as $key) {
                $row = $quotes[$key] ?? null;
                if (! is_array($row) || ($row['reliability'] ?? '') !== $wanted || ($row['sale_fee_amount'] ?? null) === null) {
                    continue;
                }

                return [
                    'amount'       => $row['sale_fee_amount'],
                    'reliability'  => $wanted,
                    'reason'       => ($row['reason'] ?? ('sale_fee_amount de listing_prices (' . $row['listing_type_id'] . ')'))
                        . ' Consulta em ' . ($row['quoted_at'] ?? '') . '.',
                    'listing_type' => $row['listing_type_id'],
                    'details'      => $row,
                ];
            }
        }

        return [
            'amount'       => null,
            'reliability'  => CostLine::UNKNOWN,
            'reason'       => $quotes['account'] === null
                ? 'Nenhuma conta Mercado Livre conectada para cotar tarifas.'
                : (($quotes['category_id'] ?? null) === null
                    ? 'Categoria MLB não confirmada. Confirme uma sugestão do preditor oficial ou informe o ID MLB no painel.'
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

    /**
     * @param array<string, mixed> $logistics
     *
     * @return array<string, mixed>|null
     */
    private function feeQuote(
        array $account,
        string $categoryId,
        string $listingTypeId,
        string $price,
        array $logistics,
        string $quotedAt,
        ?int $billableWeightGrams = null,
    ): ?array {
        try {
            $fee = ($this->fees ?? new MercadoLivreFeeService())->quote(
                $account,
                $categoryId,
                $listingTypeId,
                Decimal::round($price, 2),
                'BRL',
                is_string($logistics['logistic_type'] ?? null) ? $logistics['logistic_type'] : null,
                is_string($logistics['shipping_mode'] ?? null) ? $logistics['shipping_mode'] : null,
                $billableWeightGrams,
            );

            $row = $this->feeToArray($fee);
            $row['quoted_at'] = $quotedAt;
            $row['billable_weight'] = $billableWeightGrams;
            if (! ($logistics['ready'] ?? false)) {
                $row['reason'] .= ' Consulta sem logistic_type/mode confirmados — fixed_fee pode divergir do cobrado na venda.';
                $row['reliability'] = CostLine::ESTIMATED;
            } else {
                $row['reason'] .= ' logistic_type=' . $logistics['logistic_type']
                    . ', shipping_mode=' . $logistics['shipping_mode']
                    . ($billableWeightGrams ? ', billable_weight=' . $billableWeightGrams . 'g' : '')
                    . ' (origem logística: ' . ($logistics['source'] ?? '') . ').';
            }

            return $row;
        } catch (MercadoLivreApiException $e) {
            return [
                'listing_type_id' => $listingTypeId,
                'sale_fee_amount' => null,
                'reliability'     => CostLine::UNKNOWN,
                'reason'          => 'API listing_prices: ' . $e->userMessage(),
                'quoted_at'       => $quotedAt,
            ];
        } catch (Throwable $e) {
            return [
                'listing_type_id' => $listingTypeId,
                'sale_fee_amount' => null,
                'reliability'     => CostLine::UNKNOWN,
                'reason'          => 'Falha ao cotar tarifa: ' . $e->getMessage(),
                'quoted_at'       => $quotedAt,
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
            'reason'             => 'sale_fee_amount oficial (total por vender); fixed_fee em sale_fee_details já incluso — não somado de novo.',
        ];
    }

    /**
     * @param array<string, mixed> $logistics
     *
     * @return array{amount: ?string, reliability: string, reason: string, raw: mixed, origin: array}
     */
    private function shippingQuote(?array $account, array $product, string $salePrice, ?string $categoryId, array $logistics): array
    {
        $originMeta = $this->originMeta($product);

        if ($account === null) {
            return [
                'amount'      => null,
                'reliability' => CostLine::UNKNOWN,
                'reason'      => 'Conta ML indisponível para cotar frete do vendedor.',
                'raw'         => null,
                'origin'      => $originMeta,
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
                'origin'      => $originMeta,
            ];
        }

        if (! ($logistics['ready'] ?? false) || empty($logistics['shipping_mode']) || empty($logistics['logistic_type'])) {
            return [
                'amount'      => null,
                'reliability' => CostLine::UNKNOWN,
                'reason'      => (string) ($logistics['reason'] ?? 'Mode/logistic_type não confirmados para cotar frete.'),
                'raw'         => ['options' => $logistics['options'] ?? []],
                'origin'      => $originMeta,
            ];
        }

        try {
            $meli = $this->meli ?? service('mercadoLivre');
            $path = '/users/' . rawurlencode((string) $account['external_user_id']) . '/shipping_options/free';
            $baseQuery = array_filter([
                'dimensions'      => sprintf('%dx%dx%d,%d', (int) ceil((float) $dims['height']), (int) ceil((float) $dims['width']), (int) ceil((float) $dims['length']), $weightGrams),
                'item_price'      => Decimal::round($salePrice, 2),
                'listing_type_id' => self::TYPE_CLASSIC,
                'mode'            => (string) $logistics['shipping_mode'],
                'logistic_type'   => (string) $logistics['logistic_type'],
                'condition'       => 'new',
                'verbose'         => 'true',
                'zip_code'        => $originMeta['zip_code'] ?? '',
            ], static fn ($v) => $v !== null && $v !== '');

            if ($categoryId !== null) {
                $baseQuery['category_id'] = $categoryId;
            }

            // Doc oficial: enviar free_shipping true/false para a resposta correta do custo.
            $withFree = $meli->get((int) $account['id'], $path, $baseQuery + ['free_shipping' => 'true']);
            $noFree   = $meli->get((int) $account['id'], $path, $baseQuery + ['free_shipping' => 'false']);

            $listFree = $this->extractListCost($withFree);
            $listPaid = $this->extractListCost($noFree);
            $discount = $this->extractDiscount($withFree);

            if ($listFree === null && $listPaid === null) {
                return [
                    'amount'      => null,
                    'reliability' => CostLine::UNKNOWN,
                    'reason'      => 'A API de frete respondeu, mas sem list_cost interpretável. Custo líquido real do vendedor só aparece em /shipments/{id}/costs (senders[].cost) após envio.',
                    'raw'         => [
                        'free_shipping_true'  => $this->summarizeShipping($withFree),
                        'free_shipping_false' => $this->summarizeShipping($noFree),
                    ],
                    'origin'      => $originMeta,
                ];
            }

            // Hipótese operacional de dropshipping: anúncio com frete grátis ao comprador.
            // list_cost com free_shipping=true é o "custo de envio oferecido ao vendedor" (estimativa),
            // não o valor líquido conciliado pós-envio.
            $amount = $listFree ?? $listPaid;
            $reason = 'Estimativa oficial GET /users/{id}/shipping_options/free'
                . ' (list_cost com free_shipping=' . ($listFree !== null ? 'true' : 'false') . ').'
                . ' Mode=' . $logistics['shipping_mode'] . ', logistic_type=' . $logistics['logistic_type']
                . ', conta VITRINEHUB; ' . $originMeta['note']
                . ' Não é custo líquido confirmado (isso exige senders[].cost do envio real).'
                . ($listFree !== null && $listPaid !== null ? ' Comparativo sem frete grátis: R$ ' . $listPaid . '.' : '')
                . ($discount !== null ? ' Desconto na simulação: ' . json_encode($discount, JSON_UNESCAPED_UNICODE) . '.' : '');

            return [
                'amount'      => $amount,
                'reliability' => CostLine::ESTIMATED,
                'reason'      => $reason,
                'raw'         => [
                    'list_cost_free_shipping' => $listFree,
                    'list_cost_buyer_pays'    => $listPaid,
                    'discount'                => $discount,
                    'free_shipping_true'      => $this->summarizeShipping($withFree),
                    'free_shipping_false'     => $this->summarizeShipping($noFree),
                ],
                'origin'      => $originMeta,
            ];
        } catch (MercadoLivreApiException $e) {
            return [
                'amount'      => null,
                'reliability' => CostLine::UNKNOWN,
                'reason'      => 'Falha na cotação de frete: ' . $e->userMessage(),
                'raw'         => null,
                'origin'      => $originMeta,
            ];
        } catch (Throwable $e) {
            return [
                'amount'      => null,
                'reliability' => CostLine::UNKNOWN,
                'reason'      => 'Falha na cotação de frete: ' . $e->getMessage(),
                'raw'         => null,
                'origin'      => $originMeta,
            ];
        }
    }

    /**
     * @return array{uf: ?string, zip_code: ?string, note: string}
     */
    private function originMeta(array $product): array
    {
        $uf  = isset($product['origin_uf']) && is_string($product['origin_uf']) && $product['origin_uf'] !== ''
            ? strtoupper($product['origin_uf'])
            : null;
        $zip = null;
        foreach (['origin_postal_code', 'zip_code'] as $key) {
            if (! empty($product[$key]) && preg_match('/^\d{8}$/', preg_replace('/\D+/', '', (string) $product[$key]) ?? '')) {
                $zip = preg_replace('/\D+/', '', (string) $product[$key]);
                break;
            }
        }

        if ($zip !== null) {
            $note = 'CEP de origem do fornecedor Dropify informado (' . ($uf ? $uf . '/' : '') . $zip . '); distinto do endereço cadastral da conta VITRINEHUB.';
        } elseif ($uf !== null) {
            $note = 'UF de origem do fornecedor=' . $uf . ', sem CEP no catálogo — a cotação pode usar o endereço da conta VITRINEHUB e divergir da origem física Dropify.';
        } else {
            $note = 'Sem CEP/UF do fornecedor — cotação usa contexto da conta VITRINEHUB, não a origem física Dropify.';
        }

        return ['uf' => $uf, 'zip_code' => $zip, 'note' => $note];
    }

    private function extractListCost(array $response): ?string
    {
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

    private function extractDiscount(array $response): ?array
    {
        $discount = $response['coverage']['all_country']['discount'] ?? ($response['coverage']['discount'] ?? null);

        return is_array($discount) ? $discount : null;
    }

    private function summarizeShipping(array $response): array
    {
        $options = [];
        if (isset($response['options']) && is_array($response['options'])) {
            foreach (array_slice($response['options'], 0, 5) as $option) {
                if (! is_array($option)) {
                    continue;
                }
                $options[] = [
                    'name'      => $option['name'] ?? null,
                    'list_cost' => $option['list_cost'] ?? null,
                    'cost'      => $option['cost'] ?? null,
                ];
            }
        }

        return [
            'keys'     => array_slice(array_keys($response), 0, 20),
            'options'  => $options,
            'coverage' => $response['coverage']['all_country'] ?? null,
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

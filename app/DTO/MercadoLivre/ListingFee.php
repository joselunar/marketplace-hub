<?php

namespace App\DTO\MercadoLivre;

use App\Support\Decimal;
use UnexpectedValueException;

/**
 * Tarifa de um tipo de anúncio para um preço, como devolvida por
 * GET /sites/{SITE_ID}/listing_prices.
 *
 * percentage_fee é a comissão total sobre o preço; fixed_fee é a tarifa
 * fixa por unidade vendida (depende do preço e da logística).
 */
final class ListingFee
{
    public function __construct(
        public readonly string $listingTypeId,
        public readonly string $listingTypeName,
        public readonly string $currencyId,
        public readonly string $price,
        public readonly string $saleFeeAmount,
        public readonly string $percentageFee,
        public readonly string $fixedFee,
        public readonly string $listingFeeAmount,
        public readonly bool $requiresPicture,
    ) {
    }

    /**
     * A resposta pode vir como objeto, lista ou lista aninhada; procura o
     * item do tipo de anúncio pedido.
     */
    public static function fromResponse(array $response, string $listingTypeId, string $price): self
    {
        $entry = self::find($response, $listingTypeId);

        if ($entry === null) {
            throw new UnexpectedValueException('O Mercado Livre não devolveu tarifa para o tipo de anúncio ' . $listingTypeId . '.');
        }

        $details  = is_array($entry['sale_fee_details'] ?? null) ? $entry['sale_fee_details'] : [];
        $amount   = self::decimal($entry['sale_fee_amount'] ?? null) ?? '0.00';
        $fixed    = self::decimal($details['fixed_fee'] ?? null) ?? '0.00';
        $percent  = self::decimal($details['percentage_fee'] ?? null, 4);

        // Sem o detalhamento, o percentual sai do valor total menos a tarifa fixa.
        if ($percent === null) {
            $percent = bccomp($price, '0', 2) > 0
                ? bcdiv(bcmul(bcsub($amount, $fixed, 4), '100', 6), $price, 4)
                : '0';
        }

        return new self(
            listingTypeId: $listingTypeId,
            listingTypeName: is_string($entry['listing_type_name'] ?? null) ? $entry['listing_type_name'] : $listingTypeId,
            currencyId: is_string($entry['currency_id'] ?? null) ? $entry['currency_id'] : '',
            price: Decimal::round($price, 2),
            saleFeeAmount: $amount,
            percentageFee: Decimal::round($percent, 2),
            fixedFee: $fixed,
            listingFeeAmount: self::decimal($entry['listing_fee_amount'] ?? null) ?? '0.00',
            requiresPicture: (bool) ($entry['requires_picture'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'listing_type_id'    => $this->listingTypeId,
            'listing_type_name'  => $this->listingTypeName,
            'currency_id'        => $this->currencyId,
            'price'              => $this->price,
            'sale_fee_amount'    => $this->saleFeeAmount,
            'percentage_fee'     => $this->percentageFee,
            'fixed_fee'          => $this->fixedFee,
            'listing_fee_amount' => $this->listingFeeAmount,
            'requires_picture'   => $this->requiresPicture,
        ];
    }

    private static function find(array $node, string $listingTypeId): ?array
    {
        if (isset($node['listing_type_id'])) {
            return $node['listing_type_id'] === $listingTypeId ? $node : null;
        }

        foreach ($node as $child) {
            if (is_array($child) && ($found = self::find($child, $listingTypeId)) !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function decimal(mixed $value, int $scale = 2): ?string
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        return Decimal::round(is_float($value) ? sprintf('%.6F', $value) : (string) $value, $scale);
    }
}

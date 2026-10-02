<?php

namespace App\Services\Marketplaces\MercadoLivre;

use App\DTO\MercadoLivre\ListingDraft;

/**
 * Monta o corpo de POST /items conforme a documentação "Publicar produtos"
 * e "User Products".
 *
 * No modelo User Products o título não é enviado (o Mercado Livre gera a
 * partir de family_name e atributos) e family_name passa a ser obrigatório.
 * A descrição vai depois, em POST /items/{id}/description.
 */
class ItemPayloadBuilder
{
    public const BUYING_MODE = 'buy_it_now';

    public function build(ListingDraft $draft): array
    {
        $payload = $draft->isUserProducts()
            ? ['family_name' => $draft->familyName]
            : ['title' => $draft->title];

        $payload += [
            'category_id'        => $draft->categoryId,
            'price'              => (float) $draft->price,
            'currency_id'        => $draft->currencyId,
            'available_quantity' => $draft->quantity,
            'buying_mode'        => self::BUYING_MODE,
            'listing_type_id'    => $draft->listingTypeId,
            'condition'          => $draft->condition,
        ];

        $saleTerms = array_filter([
            'WARRANTY_TYPE' => $draft->warrantyType,
            'WARRANTY_TIME' => $draft->warrantyTime,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        if ($saleTerms !== []) {
            $payload['sale_terms'] = array_map(
                static fn (string $id, string $value): array => ['id' => $id, 'value_name' => $value],
                array_keys($saleTerms),
                $saleTerms,
            );
        }

        if ($draft->pictures !== []) {
            $payload['pictures'] = array_map(static fn (string $url): array => ['source' => $url], $draft->pictures);
        }

        if ($draft->attributes !== []) {
            $payload['attributes'] = array_map(
                static fn (string $id, string $value): array => ['id' => $id, 'value_name' => $value],
                array_keys($draft->attributes),
                array_values($draft->attributes),
            );
        }

        return $payload;
    }
}

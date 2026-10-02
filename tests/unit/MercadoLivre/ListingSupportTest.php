<?php

namespace Tests\Unit\MercadoLivre;

use App\DTO\MercadoLivre\ListingDraft;
use App\DTO\MercadoLivre\ListingFee;
use App\DTO\Pricing\PricingConfig;
use App\Services\Marketplaces\MercadoLivre\ItemPayloadBuilder;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreApiException;
use CodeIgniter\Test\CIUnitTestCase;
use UnexpectedValueException;

final class ListingSupportTest extends CIUnitTestCase
{
    public function testFeeIsFoundInObjectListOrNestedList(): void
    {
        $entry = [
            'currency_id' => 'BRL', 'listing_type_id' => 'gold_pro', 'listing_type_name' => 'Premium', 'listing_fee_amount' => 0,
            'requires_picture' => true, 'sale_fee_amount' => 37.5,
            'sale_fee_details' => ['fixed_fee' => 6.25, 'percentage_fee' => 16.5, 'financing_add_on_fee' => 0, 'gross_amount' => 37.5],
        ];
        $other = ['listing_type_id' => 'free', 'sale_fee_amount' => 0, 'sale_fee_details' => ['fixed_fee' => 0, 'percentage_fee' => 0]];

        foreach ([$entry, [$other, $entry], [[$other, $entry]]] as $response) {
            $fee = ListingFee::fromResponse($response, 'gold_pro', '189.9');

            $this->assertSame('16.50', $fee->percentageFee);
            $this->assertSame('6.25', $fee->fixedFee);
            $this->assertSame('37.50', $fee->saleFeeAmount);
            $this->assertSame('189.90', $fee->price);
            $this->assertSame('Premium', $fee->listingTypeName);
            $this->assertTrue($fee->requiresPicture);
        }
    }

    public function testPercentageIsDerivedWhenDetailsAreMissing(): void
    {
        $fee = ListingFee::fromResponse([['listing_type_id' => 'gold_special', 'sale_fee_amount' => 25]], 'gold_special', '200');

        $this->assertSame('12.50', $fee->percentageFee);
        $this->assertSame('0.00', $fee->fixedFee);
    }

    public function testMissingListingTypeIsAnError(): void
    {
        $this->expectException(UnexpectedValueException::class);
        ListingFee::fromResponse([['listing_type_id' => 'free']], 'gold_special', '100');
    }

    public function testClassicPayload(): void
    {
        $payload = (new ItemPayloadBuilder())->build(new ListingDraft(
            sellingModel: 'classic',
            categoryId: 'MLB1055',
            listingTypeId: 'gold_special',
            condition: 'new',
            currencyId: 'BRL',
            price: '1299.90',
            quantity: 4,
            title: 'Smartphone Lunar 128GB',
            familyName: 'ignorado',
            pictures: ['https://cdn.exemplo.com/1.jpg'],
            attributes: ['BRAND' => 'Lunar', 'MODEL' => 'L1'],
            warrantyType: 'Garantia do vendedor',
            warrantyTime: '90 dias',
        ));

        $this->assertSame('Smartphone Lunar 128GB', $payload['title']);
        $this->assertArrayNotHasKey('family_name', $payload);
        $this->assertSame(1299.9, $payload['price']);
        $this->assertSame(4, $payload['available_quantity']);
        $this->assertSame('buy_it_now', $payload['buying_mode']);
        $this->assertSame([['id' => 'WARRANTY_TYPE', 'value_name' => 'Garantia do vendedor'], ['id' => 'WARRANTY_TIME', 'value_name' => '90 dias']], $payload['sale_terms']);
        $this->assertSame([['id' => 'BRAND', 'value_name' => 'Lunar'], ['id' => 'MODEL', 'value_name' => 'L1']], $payload['attributes']);
        $this->assertSame([['source' => 'https://cdn.exemplo.com/1.jpg']], $payload['pictures']);
    }

    public function testUserProductsPayloadHasFamilyNameAndNoTitle(): void
    {
        $payload = (new ItemPayloadBuilder())->build(new ListingDraft(
            sellingModel: 'user_products',
            categoryId: 'MLB1055',
            listingTypeId: 'gold_pro',
            condition: 'new',
            currencyId: 'BRL',
            price: '10',
            quantity: 1,
            title: null,
            familyName: 'Smartphone Lunar 128GB',
        ));

        $this->assertSame('Smartphone Lunar 128GB', $payload['family_name']);
        $this->assertArrayNotHasKey('title', $payload);
        $this->assertArrayNotHasKey('sale_terms', $payload);
        $this->assertArrayNotHasKey('pictures', $payload);
    }

    public function testValidationCausesAreShownToTheUser(): void
    {
        $e = MercadoLivreApiException::fromResponse(400, [
            'message' => 'Validation error',
            'error'   => 'validation_error',
            'cause'   => [
                ['code' => 'item.title.length', 'message' => 'Title is too long', 'type' => 'error'],
                ['code' => 'item.pictures.size', 'message' => 'Small picture', 'type' => 'warning'],
            ],
        ]);

        $this->assertSame(['Title is too long'], $e->errorCauses());
        $this->assertStringContainsString('recusou os dados do anúncio', $e->userMessage());
        $this->assertStringContainsString('Motivos: Title is too long.', $e->userMessage());
    }

    public function testInvertedErrorBodyIsNormalized(): void
    {
        $e = MercadoLivreApiException::fromResponse(400, ['message' => 'body.invalid_fields', 'error' => 'The fields [variations] are invalid for requested call.', 'cause' => []]);

        $this->assertSame('body.invalid_fields', $e->errorCode);
        $this->assertSame('The fields [variations] are invalid for requested call.', $e->getMessage());
    }

    public function testOfficialFeesReplaceConfiguredOnes(): void
    {
        $config = new PricingConfig('25', '10', '6', '14', '6.00', '79.00', '0', '0');
        $quoted = $config->withMarketplaceFees('11.50', '0.00');

        $this->assertSame('11.50', $quoted->marketplaceFeePercentage);
        $this->assertSame('0.00', $quoted->marketplaceFixedFee);
        $this->assertSame('0.00', $quoted->fixedFeeThreshold, 'a tarifa fixa cotada já vale para o preço consultado');
        $this->assertSame('6', $quoted->taxPercentage);
    }
}

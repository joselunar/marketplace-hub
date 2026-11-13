<?php

namespace Tests\Unit\Profitability;

use App\DTO\Profitability\CostLine;
use App\DTO\Profitability\ProfitabilityInput;
use App\DTO\Profitability\ProfitabilityResult;
use App\Services\Profitability\ProfitabilityEngine;
use CodeIgniter\Test\CIUnitTestCase;

final class ProfitabilityEngineTest extends CIUnitTestCase
{
    private ProfitabilityEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ProfitabilityEngine();
    }

    public function testClassifiesAsRentavelWhenMarginMeetsMinimumAndCostsConfirmed(): void
    {
        $result = $this->engine->analyze(new ProfitabilityInput(
            salePrice: '100.00',
            costPrice: '40.00',
            marketplaceFeeTotal: '14.00',
            marketplaceFeeReliability: CostLine::CONFIRMED,
            marketplaceFeeReason: 'sale_fee_amount',
            sellerShippingCost: '0.00',
            sellerShippingReliability: CostLine::CONFIRMED,
            sellerShippingReason: 'frete comprador',
            minimumMarginPercentage: '20.00',
            returnsReservePercentage: '2.00',
            variableOpsPercentage: '0.00',
            variableOpsFixed: '0.00',
            taxPercentage: '0.00',
            taxApproved: false,
            monthlyFixedCosts: '300.00',
            monthlyUnitsAssumption: 30,
        ));

        $this->assertSame(ProfitabilityResult::RENTAVEL, $result->classification);
        $this->assertTrue($result->isPublishable());
        $this->assertSame('44.00', $result->contributionMargin); // 100 - 40 - 14 - 2
        $this->assertSame('44.00', $result->contributionMarginPct);
    }

    public function testDoesNotMarkRentavelWhenMarketplaceFeeUnknown(): void
    {
        $result = $this->engine->analyze(new ProfitabilityInput(
            salePrice: '100.00',
            costPrice: '20.00',
            marketplaceFeeTotal: null,
            marketplaceFeeReliability: CostLine::UNKNOWN,
            marketplaceFeeReason: 'sem cotacao',
            sellerShippingCost: '0.00',
            sellerShippingReliability: CostLine::CONFIRMED,
            sellerShippingReason: '',
            minimumMarginPercentage: '20.00',
        ));

        $this->assertSame(ProfitabilityResult::AGUARDANDO_DADOS, $result->classification);
        $this->assertFalse($result->isPublishable());
        $this->assertContains('tarifa_marketplace', $result->unknownEssential);
    }

    public function testClassifiesPrejuizoWhenNegativeContribution(): void
    {
        $result = $this->engine->analyze(new ProfitabilityInput(
            salePrice: '50.00',
            costPrice: '40.00',
            marketplaceFeeTotal: '15.00',
            marketplaceFeeReliability: CostLine::CONFIRMED,
            marketplaceFeeReason: '',
            sellerShippingCost: '5.00',
            sellerShippingReliability: CostLine::CONFIRMED,
            sellerShippingReason: '',
            minimumMarginPercentage: '20.00',
            returnsReservePercentage: '0.00',
        ));

        $this->assertSame(ProfitabilityResult::PREJUIZO, $result->classification);
        $this->assertFalse($result->isPublishable());
    }

    public function testClassifiesMargemBaixaWhenPositiveButBelowMinimum(): void
    {
        $result = $this->engine->analyze(new ProfitabilityInput(
            salePrice: '100.00',
            costPrice: '70.00',
            marketplaceFeeTotal: '12.00',
            marketplaceFeeReliability: CostLine::CONFIRMED,
            marketplaceFeeReason: '',
            sellerShippingCost: '0.00',
            sellerShippingReliability: CostLine::CONFIRMED,
            sellerShippingReason: '',
            minimumMarginPercentage: '20.00',
            returnsReservePercentage: '0.00',
        ));

        // margem = 18%
        $this->assertSame(ProfitabilityResult::MARGEM_BAIXA, $result->classification);
        $this->assertSame('18.00', $result->contributionMarginPct);
    }

    public function testDoesNotDoubleCountFixedFeeInsideSaleFeeAmount(): void
    {
        // O motor recebe o TOTAL já consolidado; não soma fixed_fee à parte.
        $result = $this->engine->analyze(new ProfitabilityInput(
            salePrice: '100.00',
            costPrice: '50.00',
            marketplaceFeeTotal: '16.00', // já inclui fixed_fee
            marketplaceFeeReliability: CostLine::CONFIRMED,
            marketplaceFeeReason: 'sale_fee_amount com fixed_fee incluso',
            sellerShippingCost: '0.00',
            sellerShippingReliability: CostLine::CONFIRMED,
            sellerShippingReason: '',
            minimumMarginPercentage: '10.00',
            returnsReservePercentage: '0.00',
        ));

        $this->assertSame('34.00', $result->contributionMargin);
        $feeLine = array_values(array_filter($result->lines, static fn ($l) => $l->code === 'marketplace_fee'))[0];
        $this->assertSame('16.00', $feeLine->amount);
    }
}

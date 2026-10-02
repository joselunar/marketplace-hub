<?php

namespace Tests\Unit\Pricing;

use App\DTO\Pricing\CostInput;
use App\DTO\Pricing\MarginPolicy;
use App\DTO\Pricing\PricingConfig;
use App\Services\Pricing\PriceCalculator;
use App\Services\Pricing\PricingException;
use CodeIgniter\Test\CIUnitTestCase;

final class PriceCalculatorTest extends CIUnitTestCase
{
    private PriceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PriceCalculator();
    }

    public function testSuggestedPriceCoversAllCostsAndDesiredMargin(): void
    {
        // 110 / (1 − (14 + 6 + 25) / 100) = 200
        $breakdown = $this->calculator->suggest(new CostInput('100.00', '10.00'), $this->margins('25', '10'), $this->config());

        $this->assertSame('200.00', $breakdown->price);
        $this->assertSame('28.00', $breakdown->marketplaceFee);
        $this->assertSame('12.00', $breakdown->taxAmount);
        $this->assertSame('150.00', $breakdown->totalCosts);
        $this->assertSame('50.00', $breakdown->profit);
        $this->assertSame('25.00', $breakdown->marginPercentage);
        $this->assertSame('157.15', $breakdown->minimumPrice, '110 / (1 − 0,30) = 157,142…, centavo acima');
        $this->assertFalse($breakdown->isBelowMinimum());
        $this->assertFalse($breakdown->isManual());
    }

    public function testIsNotJustCostPlusPercentage(): void
    {
        $breakdown = $this->calculator->suggest(new CostInput('100.00'), $this->margins('25', '10'), $this->config(fee: '0', tax: '0'));

        // Custo + 25% daria 125, mas a margem é sobre o preço: 100 / 0,75.
        $this->assertSame('133.34', $breakdown->price);
        $this->assertSame('25.00', $breakdown->marginPercentage);
    }

    public function testManualPriceBelowMinimumIsFlagged(): void
    {
        $breakdown = $this->calculator->at('120', new CostInput('100.00', '10.00'), $this->margins('25', '10'), $this->config());

        $this->assertTrue($breakdown->isManual());
        $this->assertSame('120.00', $breakdown->price);
        $this->assertSame('-14.00', $breakdown->profit);
        $this->assertSame('-11.67', $breakdown->marginPercentage);
        $this->assertTrue($breakdown->isBelowMinimum());
        $this->assertSame('157.15', $breakdown->minimumPrice);
        $this->assertSame('200.00', $breakdown->suggestedPrice);
    }

    public function testFixedFeeAppliesOnlyBelowThreshold(): void
    {
        $config  = $this->config(fixedFee: '6.00', threshold: '79.00');
        $margins = $this->margins('25', '10');

        // Sem a tarifa: 30 / 0,55 = 54,55 (< 79). Com ela: 36 / 0,55 = 65,46.
        $low = $this->calculator->suggest(new CostInput('30.00'), $margins, $config);
        $this->assertSame('65.46', $low->price);
        $this->assertSame('6.00', $low->marketplaceFixedFee);
        $this->assertSame('25.01', $low->marginPercentage, 'O centavo arredondado para cima sobra como margem.');

        // 50 / 0,55 = 90,91, acima da faixa: sem tarifa fixa.
        $high = $this->calculator->suggest(new CostInput('50.00'), $margins, $config);
        $this->assertSame('90.91', $high->price);
        $this->assertSame('0.00', $high->marketplaceFixedFee);
    }

    public function testPriceStopsAtThresholdWhenTheFeeWouldPushItOver(): void
    {
        // Sem a tarifa: 41 / 0,55 = 74,55 (< 79). Com ela: 47 / 0,55 = 85,45 (≥ 79).
        // No limite de 79 a tarifa não é cobrada e a margem passa da desejada.
        $breakdown = $this->calculator->suggest(new CostInput('41.00'), $this->margins('25', '10'), $this->config(fixedFee: '6.00', threshold: '79.00'));

        $this->assertSame('79.00', $breakdown->price);
        $this->assertSame('0.00', $breakdown->marketplaceFixedFee);
        $this->assertSame('28.10', $breakdown->marginPercentage);
    }

    public function testZeroThresholdChargesFixedFeeAlways(): void
    {
        $breakdown = $this->calculator->suggest(new CostInput('500.00'), $this->margins('25', '10'), $this->config(fixedFee: '5.00', threshold: '0'));

        $this->assertSame('5.00', $breakdown->marketplaceFixedFee);
        $this->assertSame('918.19', $breakdown->price, '505 / 0,55 = 918,18…, centavo acima');
    }

    public function testExtraCostsAreIncluded(): void
    {
        $config    = $this->config(extraPct: '5', extraFixed: '1.50');
        $breakdown = $this->calculator->suggest(new CostInput('96.00', '0.00', '2.50'), $this->margins('25', '10'), $config);

        // (96 + 1,50 + 2,50) / (1 − 0,50) = 200
        $this->assertSame('200.00', $breakdown->price);
        $this->assertSame('4.00', $breakdown->extraFixedCost);
        $this->assertSame('10.00', $breakdown->extraPercentageCost);
        $this->assertSame('26.00', $breakdown->otherCosts(), 'impostos 12 + extras fixos 4 + extras % 10');
        $this->assertSame('25.00', $breakdown->marginPercentage);
    }

    public function testRoundingRulesAlwaysRoundUp(): void
    {
        $this->assertSame('52.90', $this->calculator->round('52.31', PricingConfig::ROUNDING_ENDING_90));
        $this->assertSame('53.90', $this->calculator->round('52.95', PricingConfig::ROUNDING_ENDING_90));
        $this->assertSame('52.90', $this->calculator->round('52.90', PricingConfig::ROUNDING_ENDING_90));
        $this->assertSame('53.99', $this->calculator->round('52.991', PricingConfig::ROUNDING_ENDING_99));
        $this->assertSame('53.00', $this->calculator->round('52.01', PricingConfig::ROUNDING_INTEGER));
        $this->assertSame('52.00', $this->calculator->round('52.00', PricingConfig::ROUNDING_INTEGER));
        $this->assertSame('52.01', $this->calculator->round('52.001', PricingConfig::ROUNDING_CENTS));
    }

    public function testRoundedPriceKeepsAtLeastTheDesiredMargin(): void
    {
        $breakdown = $this->calculator->suggest(new CostInput('100.00'), $this->margins('25', '10'), $this->config(rounding: PricingConfig::ROUNDING_ENDING_90));

        // 100 / 0,55 = 181,82 → 181,90
        $this->assertSame('181.82', $breakdown->rawPrice);
        $this->assertSame('181.90', $breakdown->price);
        $this->assertGreaterThanOrEqual(25.0, (float) $breakdown->marginPercentage);
    }

    public function testMinimumMarginWinsWhenHigherThanDesired(): void
    {
        $margins = $this->margins('10', '20');

        $this->assertSame('20', $margins->target());

        $breakdown = $this->calculator->suggest(new CostInput('100.00'), $margins, $this->config(fee: '0', tax: '0'));
        $this->assertSame('125.00', $breakdown->price);
    }

    public function testImpossiblePercentagesThrow(): void
    {
        $this->expectException(PricingException::class);
        $this->expectExceptionMessage('somam 105%');

        $this->calculator->suggest(new CostInput('100.00'), $this->margins('25', '10'), $this->config(fee: '50', tax: '30'));
    }

    public function testManualPriceMustBePositive(): void
    {
        $this->expectException(PricingException::class);

        $this->calculator->at('0', new CostInput('10.00'), $this->margins('25', '10'), $this->config());
    }

    private function margins(string $desired, string $minimum): MarginPolicy
    {
        return new MarginPolicy($desired, MarginPolicy::SOURCE_GLOBAL, $minimum, MarginPolicy::SOURCE_GLOBAL);
    }

    private function config(
        string $fee = '14',
        string $tax = '6',
        string $fixedFee = '0',
        string $threshold = '0',
        string $extraPct = '0',
        string $extraFixed = '0',
        string $rounding = PricingConfig::ROUNDING_CENTS,
    ): PricingConfig {
        return new PricingConfig('25', '10', $tax, $fee, $fixedFee, $threshold, $extraPct, $extraFixed, $rounding);
    }
}

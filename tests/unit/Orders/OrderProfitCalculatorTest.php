<?php

namespace Tests\Unit\Orders;

use App\DTO\Pricing\PricingConfig;
use App\Services\Orders\OrderProfitCalculator;
use CodeIgniter\Test\CIUnitTestCase;

final class OrderProfitCalculatorTest extends CIUnitTestCase
{
    private OrderProfitCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new OrderProfitCalculator();
    }

    public function testProfitDiscountsFeeCostsTaxAndExtras(): void
    {
        // 2 × 150 = 300; − 36 tarifa − (90 + 5 + 1 + 2) × 2 = 196; − 8% de 300 = 24 → 44
        $profit = $this->calculator->itemProfit('150.00', 2, '36.00', '90.00', '5.00', '1.00', $this->config(tax: '6', extraPct: '2', extraFixed: '2'));

        $this->assertSame('44.00', $profit);
    }

    public function testProfitCanBeNegative(): void
    {
        $this->assertSame('-22.00', $this->calculator->itemProfit('100.00', 1, '12.00', '104.00', '0', '0', $this->config(tax: '6')));
    }

    public function testEstimatedFeeUsesListingCommissionWhenAvailable(): void
    {
        $fee = $this->calculator->estimatedFee('100.00', 3, ['sale_fee_percentage' => '11.50', 'sale_fixed_fee' => '0'], $this->config(fee: '16'));

        $this->assertSame('34.50', $fee);
    }

    public function testEstimatedFeeFallsBackToSettingsWithFixedFeeThreshold(): void
    {
        $config = $this->config(fee: '12', fixedFee: '6.25', threshold: '79');

        $this->assertSame('12.25', $this->calculator->estimatedFee('50.00', 1, null, $config), 'abaixo do limite: 6 + 6,25');
        $this->assertSame('24.00', $this->calculator->estimatedFee('100.00', 2, null, $config), 'acima do limite: só a comissão');
    }

    public function testMargin(): void
    {
        $this->assertSame('32.00', $this->calculator->margin('64.00', '200.00'));
        $this->assertNull($this->calculator->margin(null, '200.00'));
        $this->assertNull($this->calculator->margin('10.00', '0'));
    }

    private function config(string $fee = '0', string $tax = '0', string $fixedFee = '0', string $threshold = '0', string $extraPct = '0', string $extraFixed = '0'): PricingConfig
    {
        return new PricingConfig('25', '10', $tax, $fee, $fixedFee, $threshold, $extraPct, $extraFixed, PricingConfig::ROUNDING_CENTS);
    }
}

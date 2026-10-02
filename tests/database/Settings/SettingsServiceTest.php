<?php

namespace Tests\Database\Settings;

use App\Exceptions\ValidationException;
use App\Services\Settings\SettingsService;
use Tests\Support\DatabaseTestCase;

final class SettingsServiceTest extends DatabaseTestCase
{
    private function validInput(array $overrides = []): array
    {
        return $overrides + [
            'pricing.desired_margin_percentage' => '30,5',
            'pricing.minimum_margin_percentage' => '12',
            'pricing.estimated_tax_percentage'  => '6',
            'stock.safety_quantity'             => '3',
            'suppliers.max_shipping_days'       => '5',
            'suppliers.reliability_window_days' => '30',
            'ranking.weight_price'              => '40',
            'ranking.weight_stock'              => '20',
            'ranking.weight_shipping'           => '20',
            'ranking.weight_reliability'        => '15',
            'ranking.weight_priority'           => '5',
            'sync.auto_update_stock'            => '1',
        ];
    }

    public function testReturnsDefaultsWhenNotPersisted(): void
    {
        $settings = new SettingsService();

        $this->assertSame(2, $settings->getInt('stock.safety_quantity'));
        $this->assertSame('25.00', $settings->getDecimal('pricing.desired_margin_percentage'));
        $this->assertFalse($settings->getBool('sync.auto_update_price'));
    }

    public function testSeedDefaultsIsIdempotent(): void
    {
        $settings = new SettingsService();

        $this->assertGreaterThan(0, $settings->seedDefaults());
        $this->assertSame(0, $settings->seedDefaults());
    }

    public function testUpdatesAndCastsValues(): void
    {
        $settings = new SettingsService();
        $settings->updateMany($this->validInput());

        $fresh = new SettingsService();
        $this->assertSame('30.50', $fresh->getDecimal('pricing.desired_margin_percentage'));
        $this->assertSame(3, $fresh->getInt('stock.safety_quantity'));
        $this->assertTrue($fresh->getBool('sync.auto_update_stock'));
        $this->assertFalse($fresh->getBool('sync.auto_update_price'));
        $this->seeInDatabase('audit_logs', ['entity_type' => 'settings', 'action' => 'update']);
    }

    public function testRejectsInvalidValues(): void
    {
        try {
            (new SettingsService())->updateMany($this->validInput(['stock.safety_quantity' => '-1']));
            $this->fail('Era esperada uma ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock.safety_quantity', $e->getErrors());
        }
    }

    public function testMinimumMarginCannotExceedDesired(): void
    {
        $this->expectException(ValidationException::class);

        (new SettingsService())->updateMany($this->validInput(['pricing.minimum_margin_percentage' => '40']));
    }

    public function testReportsChangedKeys(): void
    {
        $settings = new SettingsService();
        $settings->updateMany($this->validInput());

        $changed = (new SettingsService())->updateMany($this->validInput(['ranking.weight_price' => '45']));

        $this->assertSame(['ranking.weight_price'], $changed);
    }
}

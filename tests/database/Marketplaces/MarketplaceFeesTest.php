<?php

namespace Tests\Database\Marketplaces;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Services\Marketplaces\MarketplaceService;
use Tests\Support\DatabaseTestCase;

final class MarketplaceFeesTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        service('settings')->seedDefaults();
        $this->db->table('settings')->where('key', 'pricing.marketplace_fee_percentage')->update(['value' => '14.00']);
        $this->db->table('settings')->where('key', 'pricing.marketplace_fixed_fee')->update(['value' => '6.00']);
        $this->db->table('settings')->where('key', 'pricing.marketplace_fixed_fee_threshold')->update(['value' => '79.00']);
    }

    public function testMarketplaceFeesOverrideTheGlobalOnesFieldByField(): void
    {
        $pricing = service('pricing');
        $this->assertSame('14.00', $pricing->config('mercadolivre')->marketplaceFeePercentage, 'sem tarifa própria vale a global');

        (new MarketplaceService())->updateFees('mercadolivre', ['fee_percentage' => '11,5', 'fixed_fee' => '', 'fixed_fee_threshold' => ' '], $this->createUser()['id']);

        $config = $pricing->config('mercadolivre');
        $this->assertSame('11.50', $config->marketplaceFeePercentage);
        $this->assertSame('6.00', $config->marketplaceFixedFee, 'campo vazio herda o global');
        $this->assertSame('79.00', $config->fixedFeeThreshold);
        $this->assertSame('14.00', $pricing->config()->marketplaceFeePercentage, 'o global não muda');

        $row = $this->db->table('marketplaces')->where('slug', 'mercadolivre')->get()->getRowArray();
        $this->assertSame('11.50', $pricing->configForMarketplaceId((int) $row['id'])->marketplaceFeePercentage);
        $this->assertNull($row['fixed_fee']);
        $this->seeInDatabase('audit_logs', ['entity_type' => 'marketplace', 'action' => 'update_fees', 'entity_id' => $row['id']]);
    }

    public function testSimulatorCanUseTheMarketplaceFees(): void
    {
        (new MarketplaceService())->updateFees('mercadolivre', ['fee_percentage' => '10', 'fixed_fee' => '0', 'fixed_fee_threshold' => '0']);
        $pricing = service('pricing');

        $global = $pricing->simulate(['cost_price' => '100', 'sale_price' => '200']);
        $meli   = $pricing->simulate(['cost_price' => '100', 'sale_price' => '200', 'marketplace' => 'mercadolivre']);

        $this->assertSame('28.00', $global->marketplaceFee, '14% de 200 + tarifa fixa de 0 acima de 79');
        $this->assertSame('20.00', $meli->marketplaceFee);

        $this->expectException(ValidationException::class);
        $pricing->simulate(['cost_price' => '100', 'marketplace' => 'shopee']);
    }

    public function testInvalidFeesAreRejected(): void
    {
        $service = new MarketplaceService();

        try {
            $service->updateFees('mercadolivre', ['fee_percentage' => '100', 'fixed_fee' => '-1', 'fixed_fee_threshold' => 'abc']);
            $this->fail('Tarifas inválidas foram aceitas.');
        } catch (ValidationException $e) {
            $this->assertSame(['fee_percentage', 'fixed_fee', 'fixed_fee_threshold'], array_keys($e->getErrors()));
        }

        $this->assertNull($this->db->table('marketplaces')->where('slug', 'mercadolivre')->get()->getRowArray()['fee_percentage']);

        $this->expectException(BusinessRuleException::class);
        $service->updateFees('shopee', []);
    }
}

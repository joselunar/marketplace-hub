<?php

namespace Tests\Unit\Suppliers;

use App\DTO\Suppliers\OfferEvaluation;
use App\DTO\Suppliers\RankingConfig;
use App\Services\Suppliers\Selection\SupplierRanker;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

final class SupplierRankerTest extends CIUnitTestCase
{
    private SupplierRanker $ranker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ranker = new SupplierRanker();
    }

    public function testExcludesIneligibleOffersWithReasons(): void
    {
        $offers = [
            $this->offer(1, ['supplier_status' => 'inactive']),
            $this->offer(2, ['supplier_status' => 'blocked']),
            $this->offer(3, ['active' => 0]),
            $this->offer(4, ['blocked' => 1, 'blocked_reason' => 'Atrasos']),
            $this->offer(5, ['stock_quantity' => 0]),
            $this->offer(6, ['shipping_days' => 12]),
            $this->offer(7, ['shipping_days' => null, 'default_shipping_days' => null]),
            $this->offer(8, ['cost_price' => '0.00']),
            $this->offer(9),
        ];

        $result = $this->ranker->rank(['id' => 1], $offers, $this->config());

        $this->assertSame(9, $result->primary()?->offerId);
        $this->assertCount(8, $result->ineligible());
        $this->assertSame(['Fornecedor inativo'], $result->find(1)->ineligibilityReasons);
        $this->assertSame(['Fornecedor bloqueado'], $result->find(2)->ineligibilityReasons);
        $this->assertSame(['Oferta inativa'], $result->find(3)->ineligibilityReasons);
        $this->assertSame(['Oferta bloqueada manualmente: Atrasos'], $result->find(4)->ineligibilityReasons);
        $this->assertSame(['Sem estoque no fornecedor'], $result->find(5)->ineligibilityReasons);
        $this->assertSame(['Prazo de 12 dias acima do limite de 7'], $result->find(6)->ineligibilityReasons);
        $this->assertSame(['Prazo de postagem não informado'], $result->find(7)->ineligibilityReasons);
        $this->assertSame(['Custo inválido'], $result->find(8)->ineligibilityReasons);
        $this->assertNull($result->find(1)->rank);
    }

    public function testSafetyStockMakesLowStockOfferIneligible(): void
    {
        $result = $this->ranker->rank(['id' => 1], [$this->offer(1, ['stock_quantity' => 2])], $this->config(safety: 2));

        $evaluation = $result->find(1);
        $this->assertNull($result->primary());
        $this->assertSame(0, $evaluation->availableStock);
        $this->assertSame(['Estoque de segurança: 2 informado, 2 reservado'], $evaluation->ineligibilityReasons);
    }

    public function testMostSpecificSafetyStockWins(): void
    {
        $config = $this->config(safety: 2);
        $offer  = $this->offer(1, ['stock_quantity' => 10, 'supplier_safety_stock' => 5]);

        $global   = $this->ranker->rank(['id' => 1], [$this->offer(1, ['stock_quantity' => 10])], $config)->find(1);
        $supplier = $this->ranker->rank(['id' => 1], [$offer], $config)->find(1);
        $product  = $this->ranker->rank(['id' => 1, 'safety_stock' => '1'], [$offer], $config)->find(1);
        $zero     = $this->ranker->rank(['id' => 1, 'safety_stock' => '0'], [$offer], $config)->find(1);

        $this->assertSame([8, OfferEvaluation::SAFETY_SOURCE_GLOBAL], [$global->availableStock, $global->safetySource]);
        $this->assertSame([5, OfferEvaluation::SAFETY_SOURCE_SUPPLIER], [$supplier->availableStock, $supplier->safetySource]);
        $this->assertSame([9, OfferEvaluation::SAFETY_SOURCE_PRODUCT], [$product->availableStock, $product->safetySource]);
        $this->assertSame(10, $zero->availableStock, 'Zero no produto também é uma regra explícita.');
    }

    public function testRanksPrimaryAndReserves(): void
    {
        $offers = [
            // Mais barato, porém pouco estoque e prazo maior.
            $this->offer(1, ['cost_price' => '90.00', 'stock_quantity' => 12, 'shipping_days' => 5, 'supplier_name' => 'Alpha']),
            // Muito estoque e postagem rápida.
            $this->offer(2, ['cost_price' => '100.00', 'stock_quantity' => 202, 'shipping_days' => 1, 'supplier_name' => 'Gamma']),
            $this->offer(3, ['cost_price' => '110.00', 'stock_quantity' => 52, 'shipping_days' => 2, 'supplier_name' => 'Delta']),
        ];

        $result = $this->ranker->rank(['id' => 1], $offers, $this->config());

        $this->assertSame('Gamma', $result->primary()->supplierName);
        $this->assertSame(['Delta', 'Alpha'], array_map(static fn ($e) => $e->supplierName, $result->reserves()));
        $this->assertSame([1, 2, 3], array_map(static fn ($e) => $e->rank, $result->evaluations));
        $this->assertSame('Principal', $result->primary()->positionLabel());
        $this->assertSame('Reserva #1', $result->reserves()[0]->positionLabel());

        $criteria = $result->primary()->criteria;
        $this->assertSame(100.0, $criteria['stock']['score']);
        $this->assertSame(87.5, $criteria['shipping']['score']);
        $this->assertSame(90.0, $criteria['price']['score']);
        $this->assertSame('excelente', $criteria['stock']['rating']);
    }

    public function testComputesWeightedScore(): void
    {
        // Oferta única: preço e estoque = 100; prazo 3 com limite 7 = (8 - 3) / 8 = 62,5;
        // confiabilidade 80; prioridade 6 = 60.
        $offers      = [$this->offer(1, ['shipping_days' => 3, 'supplier_priority' => 6])];
        $reliability = [10 => ['score' => 80.0, 'total' => 5, 'errors' => 1, 'warnings' => 0]];

        $result = $this->ranker->rank(['id' => 1], $offers, $this->config(), $reliability);

        // (100×35 + 100×25 + 62,5×20 + 80×15 + 60×5) / 100 = 87,5
        $this->assertSame(87.5, $result->primary()->score);
        $this->assertSame('1 falha e 0 alertas em 5 sincronizações (30 dias)', $result->primary()->criteria['reliability']['note']);
    }

    public function testWeightsChangeTheWinner(): void
    {
        $offers = [
            $this->offer(1, ['cost_price' => '50.00', 'stock_quantity' => 5]),
            $this->offer(2, ['cost_price' => '80.00', 'stock_quantity' => 500]),
        ];

        $priceOnly = $this->config(weights: ['price' => '100', 'stock' => '0', 'shipping' => '0', 'reliability' => '0', 'priority' => '0']);
        $stockOnly = $this->config(weights: ['price' => '0', 'stock' => '100', 'shipping' => '0', 'reliability' => '0', 'priority' => '0']);

        $this->assertSame(1, $this->ranker->rank(['id' => 1], $offers, $priceOnly)->primary()->offerId);
        $this->assertSame(2, $this->ranker->rank(['id' => 1], $offers, $stockOnly)->primary()->offerId);
    }

    public function testTieIsBrokenByLowerTotalCost(): void
    {
        $weights = ['price' => '0', 'stock' => '0', 'shipping' => '0', 'reliability' => '0', 'priority' => '100'];
        $offers  = [
            $this->offer(1, ['cost_price' => '60.00']),
            $this->offer(2, ['cost_price' => '55.00']),
        ];

        $result = $this->ranker->rank(['id' => 1], $offers, $this->config(weights: $weights));

        $this->assertSame($result->evaluations[0]->score, $result->evaluations[1]->score);
        $this->assertSame(2, $result->primary()->offerId);
    }

    public function testUsesSupplierDefaultShippingDays(): void
    {
        $result = $this->ranker->rank(['id' => 1], [$this->offer(1, ['shipping_days' => null, 'default_shipping_days' => 4])], $this->config());

        $this->assertSame(4, $result->primary()->shippingDays);
    }

    public function testConfigRejectsAllZeroWeights(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->config(weights: ['price' => '0', 'stock' => '0', 'shipping' => '0', 'reliability' => '0', 'priority' => '0']);
    }

    private function config(int $safety = 0, ?array $weights = null): RankingConfig
    {
        return new RankingConfig(
            $weights ?? ['price' => '35', 'stock' => '25', 'shipping' => '20', 'reliability' => '15', 'priority' => '5'],
            $safety,
            7,
            30,
        );
    }

    private function offer(int $id, array $overrides = []): array
    {
        return $overrides + [
            'id'                    => $id,
            'supplier_id'           => 10,
            'supplier_name'         => 'Fornecedor ' . $id,
            'supplier_sku'          => 'SKU-' . $id,
            'supplier_status'       => 'active',
            'supplier_priority'     => 5,
            'supplier_safety_stock' => null,
            'default_shipping_days' => 2,
            'cost_price'            => '100.00',
            'shipping_cost'         => '0.00',
            'stock_quantity'        => 10,
            'shipping_days'         => 2,
            'active'                => 1,
            'blocked'               => 0,
            'blocked_reason'        => null,
        ];
    }
}

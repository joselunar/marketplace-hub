<?php

namespace Tests\Feature;

use App\Database\Seeds\DevelopmentSeeder;
use Tests\Support\FeatureTestCase;

final class ApiTest extends FeatureTestCase
{
    public function testApiRequiresAuthentication(): void
    {
        $result = $this->get('api/products');

        $result->assertStatus(401);
        $this->assertSame(['error' => 'Não autenticado.'], json_decode($result->getJSON(), true));
    }

    public function testListsProductsWithPagination(): void
    {
        $this->seed(DevelopmentSeeder::class);
        $user = $this->createUser('operator');

        $result = $this->actingAs($user)->get('api/products?per_page=3');

        $result->assertOK();
        $payload = json_decode($result->getJSON(), true);
        $this->assertCount(3, $payload['data']);
        $this->assertSame(8, $payload['meta']['total']);
        $this->assertSame(3, $payload['meta']['last_page']);
    }

    public function testSearchesProductsBySkuOrName(): void
    {
        $this->seed(DevelopmentSeeder::class);
        $user = $this->createUser('operator');

        $payload = json_decode($this->actingAs($user)->get('api/products?q=FONE-LP')->getJSON(), true);

        $this->assertSame('FONE-LP40', $payload['data'][0]['sku']);
    }

    public function testReturnsProductOffers(): void
    {
        $this->seed(DevelopmentSeeder::class);
        $user      = $this->createUser('operator');
        $productId = (int) $this->db->table('products')->where('sku', 'SMW-FIT-PRO')->get()->getRow()->id;

        $payload = json_decode($this->actingAs($user)->get("api/products/{$productId}/offers")->getJSON(), true);

        $this->assertCount(3, $payload['data']);
        $this->assertSame('95.00', $payload['data'][0]['cost_price']);
        $this->assertSame(1, $payload['data'][0]['stock_quantity']);
    }

    public function testUnknownProductReturns404(): void
    {
        $user = $this->createUser('operator');

        $this->actingAs($user)->get('api/products/999999')->assertStatus(404);
    }

    public function testSupplierEndpointDoesNotLeakUnexpectedFields(): void
    {
        $this->seed(DevelopmentSeeder::class);
        $user = $this->createUser('operator');

        $payload = json_decode($this->actingAs($user)->get('api/suppliers')->getJSON(), true);

        $this->assertSame(3, $payload['meta']['total']);
        $this->assertArrayHasKey('offers_count', $payload['data'][0]);
        $this->assertArrayNotHasKey('notes', $payload['data'][0]);
    }
}

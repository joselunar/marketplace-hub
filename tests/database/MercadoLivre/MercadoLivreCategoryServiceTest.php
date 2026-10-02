<?php

namespace Tests\Database\MercadoLivre;

use App\DTO\MercadoLivre\TokenSet;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Services\Marketplaces\MarketplaceAccountService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreApiClient;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreAuthService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreCategoryService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreService;
use CodeIgniter\Test\Mock\MockCache;
use Config\MercadoLivre;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeMercadoLivreTransport;

final class MercadoLivreCategoryServiceTest extends DatabaseTestCase
{
    private FakeMercadoLivreTransport $transport;
    private MercadoLivreService $meli;
    private MercadoLivreCategoryService $categories;

    protected function setUp(): void
    {
        parent::setUp();

        $config               = new MercadoLivre();
        $config->clientId     = 'APP123';
        $config->clientSecret = 'segredo-do-app';
        $config->redirectUri  = 'http://localhost:8080/marketplaces/mercadolivre/callback';

        $this->transport  = new FakeMercadoLivreTransport();
        $api              = new MercadoLivreApiClient($config, $this->transport);
        $accounts         = new MarketplaceAccountService();
        $this->meli       = new MercadoLivreService($config, $api, new MercadoLivreAuthService($config, $api, $accounts), $accounts);
        $this->categories = new MercadoLivreCategoryService($this->meli, new MockCache());
    }

    public function testWithoutConnectedAccountExplainsWhatToDo(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Nenhuma conta do Mercado Livre conectada');
        $this->meli->resolveAccount();
    }

    public function testRootsUseAccountSiteAndAreCached(): void
    {
        $account = $this->account();
        $this->transport->push(200, [['id' => 'MLB5672', 'name' => 'Acessórios para Veículos'], ['id' => 'MLB1051', 'name' => 'Celulares e Telefones']]);

        $first  = $this->categories->roots($account);
        $second = $this->categories->roots($account);

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->transport->requests);
        $this->assertSame('https://api.mercadolibre.com/sites/MLB/categories', $this->transport->requests[0]['url']);
        $this->assertStringStartsWith('Bearer APP_USR-', $this->transport->requests[0]['headers']['Authorization']);
        $this->assertSame('Celulares e Telefones', $first[1]['name']);
    }

    public function testCategoryDetailsAndAttributes(): void
    {
        $account = $this->account();
        $this->transport->push(200, [
            'id'                           => 'MLB1055',
            'name'                         => 'Celulares e Smartphones',
            'total_items_in_this_category' => 1500,
            'path_from_root'               => [['id' => 'MLB1051', 'name' => 'Celulares e Telefones'], ['id' => 'MLB1055', 'name' => 'Celulares e Smartphones']],
            'children_categories'          => [],
            'settings'                     => ['listing_allowed' => true, 'status' => 'enabled', 'max_title_length' => 60, 'item_conditions' => ['new', 'used']],
        ])->push(200, [
            ['id' => 'COLOR', 'name' => 'Cor', 'value_type' => 'list', 'tags' => ['allow_variations' => true], 'values' => [['id' => '1', 'name' => 'Preto'], ['id' => '2', 'name' => 'Branco']], 'attribute_group_name' => 'Outros'],
            ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true], 'value_max_length' => 255],
            ['id' => 'GTIN', 'name' => 'Código universal', 'value_type' => 'string', 'tags' => ['conditional_required']],
        ]);

        $category   = $this->categories->category($account, 'mlb1055');
        $attributes = $this->categories->attributes($account, 'MLB1055');

        $this->assertTrue($category['is_leaf']);
        $this->assertTrue($category['settings']['listing_allowed']);
        $this->assertSame(60, $category['settings']['max_title_length']);
        $this->assertSame('Celulares e Telefones', $category['path'][0]['name']);
        $this->assertSame('https://api.mercadolibre.com/categories/MLB1055', $this->transport->requests[0]['url']);
        $this->assertSame('https://api.mercadolibre.com/categories/MLB1055/attributes', $this->transport->requests[1]['url']);

        $this->assertSame('BRAND', $attributes[0]['id'], 'obrigatórios primeiro');
        $this->assertTrue($attributes[0]['required']);

        $byId = array_column($attributes, null, 'id');
        $this->assertSame(['conditional_required'], $byId['GTIN']['tags'], 'tags em lista também são aceitas');
        $this->assertFalse($byId['GTIN']['required']);
        $this->assertSame(['Preto', 'Branco'], $byId['COLOR']['values']);
        $this->assertSame(['allow_variations'], $byId['COLOR']['tags']);
    }

    public function testInvalidCategoryIdNeverReachesTheApi(): void
    {
        $account = $this->account();

        $this->expectException(BusinessRuleException::class);
        try {
            $this->categories->category($account, '../users/me');
        } finally {
            $this->assertSame([], $this->transport->requests);
        }
    }

    public function testPredictorSendsTitleAsQuery(): void
    {
        $account = $this->account();
        $this->transport->push(200, [[
            'domain_id'     => 'MLB-HEADPHONES',
            'domain_name'   => 'Fones de ouvido',
            'category_id'   => 'MLB196208',
            'category_name' => 'Fones de Ouvido',
            'attributes'    => [['id' => 'BRAND', 'name' => 'Marca', 'value_id' => '1', 'value_name' => 'Lunar']],
        ]]);

        $predictions = $this->categories->predict($account, 'Fone bluetooth sem fio');

        $this->assertSame('MLB196208', $predictions[0]['category_id']);
        $this->assertSame('Lunar', $predictions[0]['attributes'][0]['value_name']);
        $this->assertSame('https://api.mercadolibre.com/sites/MLB/domain_discovery/search?q=Fone%20bluetooth%20sem%20fio&limit=5', $this->transport->requests[0]['url']);

        $this->expectException(ValidationException::class);
        $this->categories->predict($account, 'ab');
    }

    private function account(): array
    {
        $tokens = TokenSet::fromResponse(FakeMercadoLivreTransport::tokenResponse());
        $result = $this->meli->accounts()->storeConnection('mercadolivre', $tokens, 'LOJA_TESTE', 'MLB', null);

        return $this->meli->resolveAccount($result['id']);
    }
}

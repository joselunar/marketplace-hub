<?php

namespace Tests\Unit\Suppliers;

use App\Services\Suppliers\Connectors\DropifyCsvConnector;
use CodeIgniter\Test\CIUnitTestCase;

final class DropifyCsvConnectorTest extends CIUnitTestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'dropify');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    public function testParsesBomSemicolonAndMapsDropifyHeaders(): void
    {
        $fixture = ROOTPATH . 'tests/fixtures/dropify/produtos-amostra.csv';
        $this->assertFileExists($fixture);

        $parsed = (new DropifyCsvConnector($fixture))->parse();

        $this->assertSame(5, $parsed['total']);
        $this->assertCount(5, $parsed['items']);
        $this->assertSame([], $parsed['errors']);

        $first = $parsed['items'][0];
        $this->assertSame('1001', $first->supplierSku);
        $this->assertSame('45.90', $first->costPrice);
        $this->assertSame('129.90', $first->suggestedPrice);
        $this->assertSame(85, $first->weightGrams);
        $this->assertSame('SP', $first->originUf);
        $this->assertSame('01001000', $first->originPostalCode);
        $this->assertSame('85183000', $first->ncm);
    }

    public function testRejectsDuplicateSku(): void
    {
        file_put_contents($this->file, "\xEF\xBB\xBFSKU;Nome do Produto;Compre por (R$);Estoque\nA;Produto A;10,00;1\nA;Produto A2;11,00;2\n");

        $parsed = (new DropifyCsvConnector($this->file))->parse();
        $this->assertCount(1, $parsed['items']);
        $this->assertCount(1, $parsed['errors']);
        $this->assertStringContainsString('repetido', $parsed['errors'][0]['message']);
    }
}

<?php

namespace Tests\Unit\Suppliers;

use App\Services\Suppliers\Connectors\CsvSupplierConnector;
use App\Services\Suppliers\Connectors\SupplierConnectorInterface;
use App\Services\Suppliers\Connectors\UnsupportedOperationException;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

final class CsvSupplierConnectorTest extends CIUnitTestCase
{
    /**
     * @var list<string>
     */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function csv(string $content): CsvSupplierConnector
    {
        $path = tempnam(sys_get_temp_dir(), 'csvtest');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return new CsvSupplierConnector($path);
    }

    public function testParsesSemicolonFileWithPortugueseHeadersAndBom(): void
    {
        $result = $this->csv(
            "\xEF\xBB\xBFCódigo;Nome;Custo;Estoque;EAN;Marca;Frete;Prazo\n"
            . "A1;Fone Bluetooth;1.234,50;10;7890000000017;Lenovo;12,00;2\n",
        )->fetchProducts();

        $this->assertSame(1, $result->totalRows);
        $this->assertSame([], $result->errors);

        $item = $result->items[0];
        $this->assertSame('A1', $item->supplierSku);
        $this->assertSame('Fone Bluetooth', $item->name);
        $this->assertSame('1234.50', $item->costPrice);
        $this->assertSame(10, $item->stockQuantity);
        $this->assertSame('7890000000017', $item->ean);
        $this->assertSame('Lenovo', $item->brand);
        $this->assertSame('12.00', $item->shippingCost);
        $this->assertSame(2, $item->shippingDays);
    }

    public function testParsesCommaSeparatedFileWithCanonicalHeaders(): void
    {
        $result = $this->csv(
            "supplier_sku,name,cost_price,stock_quantity,product_sku\n"
            . "X-1,\"Cabo, HDMI\",19.90,5,cabo-01\n",
        )->fetchProducts();

        $this->assertCount(1, $result->items);
        $this->assertSame('Cabo, HDMI', $result->items[0]->name);
        $this->assertSame('CABO-01', $result->items[0]->productSku);
    }

    public function testCollectsRowErrorsWithoutStoppingTheImport(): void
    {
        $result = $this->csv(
            "supplier_sku;name;cost_price;stock_quantity;ean\n"
            . "OK-1;Produto válido;10,00;3;\n"
            . ";Sem SKU;10,00;3;\n"
            . "C-0;Custo zero;0;3;\n"
            . "S-X;Estoque inválido;10;-2;\n"
            . "E-1;EAN curto;10;1;123\n"
            . "OK-1;Duplicado;11;1;\n"
            . "\n"
            . "OK-2;Outro válido;5.5;0;\n",
        )->fetchProducts();

        $this->assertSame(['OK-1', 'OK-2'], array_map(static fn ($i) => $i->supplierSku, $result->items));
        $this->assertCount(5, $result->errors);
        $this->assertSame([3, 4, 5, 6, 7], array_column($result->errors, 'line'));
        $this->assertStringContainsString('repetido', $result->errors[4]['message']);
        $this->assertSame(7, $result->totalRows);
    }

    public function testRejectsFileWithoutRequiredColumns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cost_price');

        $this->csv("supplier_sku;name;stock_quantity\nA;B;1\n")->fetchProducts();
    }

    public function testRejectsEmptyFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->csv('')->fetchProducts();
    }

    public function testConvertsWindows1252ToUtf8AndStripsTags(): void
    {
        $line   = mb_convert_encoding("A1;<b>Caixa de Som Ação</b>;10;1\n", 'Windows-1252', 'UTF-8');
        $result = $this->csv("supplier_sku;name;cost_price;stock_quantity\n" . $line)->fetchProducts();

        $this->assertSame('Caixa de Som Ação', $result->items[0]->name);
    }

    public function testAcceptsSpreadsheetStyleIntegers(): void
    {
        $result = $this->csv("supplier_sku;name;cost_price;stock_quantity\nA1;Produto;10;15,00\n")->fetchProducts();

        $this->assertSame(15, $result->items[0]->stockQuantity);
    }

    public function testDeclaresOnlyImplementedCapabilities(): void
    {
        $connector = new CsvSupplierConnector('/caminho/inexistente.csv');

        $this->assertTrue($connector->supports(SupplierConnectorInterface::CAPABILITY_FETCH_PRODUCTS));
        $this->assertFalse($connector->supports(SupplierConnectorInterface::CAPABILITY_CREATE_ORDER));
        $this->assertFalse($connector->testConnection()->success);

        $this->expectException(UnsupportedOperationException::class);
        $connector->createOrder([]);
    }
}

<?php

namespace App\Services\Suppliers\Connectors;

use App\DTO\Suppliers\ConnectionTestResult;
use App\DTO\Suppliers\SupplierFeedResult;
use App\DTO\Suppliers\SupplierProductData;
use App\Support\Decimal;
use InvalidArgumentException;
use RuntimeException;
use SplFileObject;

/**
 * Lê planilhas CSV enviadas pelo fornecedor.
 *
 * Colunas obrigatórias: supplier_sku, name, cost_price, stock_quantity.
 * Opcionais: ean, brand, shipping_cost, shipping_days, product_sku.
 * Aceita cabeçalhos em português (ver HEADER_ALIASES), separador ";" "," ou TAB
 * e arquivos UTF-8 ou Windows-1252 (padrão do Excel no Brasil).
 */
class CsvSupplierConnector extends AbstractSupplierConnector
{
    public const MAX_ROWS = 10000;

    public const REQUIRED_COLUMNS = ['supplier_sku', 'name', 'cost_price', 'stock_quantity'];

    /**
     * @var array<string, list<string>>
     */
    public const HEADER_ALIASES = [
        'supplier_sku'   => ['supplier_sku', 'sku_fornecedor', 'codigo_fornecedor', 'codigo', 'cod'],
        'name'           => ['name', 'nome', 'produto', 'descricao'],
        'cost_price'     => ['cost_price', 'custo', 'preco_custo', 'preco'],
        'stock_quantity' => ['stock_quantity', 'estoque', 'quantidade', 'qtd'],
        'ean'            => ['ean', 'gtin', 'codigo_barras'],
        'brand'          => ['brand', 'marca'],
        'shipping_cost'  => ['shipping_cost', 'frete', 'custo_frete'],
        'shipping_days'  => ['shipping_days', 'prazo', 'prazo_postagem', 'prazo_envio'],
        'product_sku'    => ['product_sku', 'sku_interno', 'sku_produto'],
    ];

    public function __construct(private readonly string $filePath)
    {
    }

    protected function capabilities(): array
    {
        return [self::CAPABILITY_TEST_CONNECTION, self::CAPABILITY_FETCH_PRODUCTS];
    }

    public function testConnection(): ConnectionTestResult
    {
        if ($this->filePath === '') {
            return ConnectionTestResult::ok('Fornecedor via planilha CSV: os dados são atualizados pelo envio de arquivos em Importações.');
        }

        if (! is_file($this->filePath) || ! is_readable($this->filePath)) {
            return ConnectionTestResult::fail('Arquivo CSV não encontrado ou sem permissão de leitura.');
        }

        return ConnectionTestResult::ok('Arquivo CSV disponível para leitura.');
    }

    public function fetchProducts(): SupplierFeedResult
    {
        if (! is_file($this->filePath) || ! is_readable($this->filePath)) {
            throw new RuntimeException('Arquivo CSV não encontrado ou sem permissão de leitura.');
        }

        $file = new SplFileObject($this->filePath, 'rb');
        $firstLine = (string) $file->fgets();
        $delimiter = $this->detectDelimiter($firstLine);
        $file->rewind();
        $file->setFlags(SplFileObject::READ_CSV);
        $file->setCsvControl($delimiter, '"', '');

        $columns = null;
        $items   = [];
        $errors  = [];
        $seen    = [];
        $total   = 0;

        foreach ($file as $index => $row) {
            $line = $index + 1;

            if (! is_array($row) || $row === [null] || $this->isBlankRow($row)) {
                continue;
            }
            $row = array_map(fn ($value) => $this->toUtf8((string) $value), $row);

            if ($columns === null) {
                $columns = $this->mapHeader($row);

                continue;
            }

            $total++;

            if ($total > self::MAX_ROWS) {
                $errors[] = ['line' => $line, 'message' => 'Limite de ' . self::MAX_ROWS . ' linhas por arquivo atingido; as linhas restantes foram ignoradas.'];
                $total--;

                break;
            }

            $record = [];

            foreach ($columns as $index => $field) {
                $record[$field] = $row[$index] ?? '';
            }

            try {
                $item = $this->buildItem($record);
            } catch (InvalidArgumentException $e) {
                $errors[] = ['line' => $line, 'message' => $e->getMessage()];

                continue;
            }

            $skuKey = mb_strtolower($item->supplierSku);

            if (isset($seen[$skuKey])) {
                $errors[] = ['line' => $line, 'message' => "SKU do fornecedor \"{$item->supplierSku}\" repetido (já informado na linha {$seen[$skuKey]})."];

                continue;
            }
            $seen[$skuKey] = $line;
            $items[]       = $item;
        }

        if ($columns === null) {
            throw new InvalidArgumentException('O arquivo CSV está vazio.');
        }

        return new SupplierFeedResult($items, $errors, $total);
    }

    /**
     * Mapeia o cabeçalho para os nomes canônicos de coluna.
     *
     * @param list<string> $header
     *
     * @return array<int, string> índice da coluna => campo canônico
     */
    private function mapHeader(array $header): array
    {
        $lookup = [];

        foreach (self::HEADER_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $lookup[$alias] = $field;
            }
        }

        $columns = [];

        foreach ($header as $index => $name) {
            $normalized = $this->normalizeHeader($name);

            if (isset($lookup[$normalized]) && ! in_array($lookup[$normalized], $columns, true)) {
                $columns[$index] = $lookup[$normalized];
            }
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, $columns);

        if ($missing !== []) {
            throw new InvalidArgumentException('Colunas obrigatórias ausentes no CSV: ' . implode(', ', $missing) . '.');
        }

        return $columns;
    }

    /**
     * @param array<string, string> $record
     */
    private function buildItem(array $record): SupplierProductData
    {
        $supplierSku = $this->cleanText($record['supplier_sku'] ?? '');
        $name        = $this->cleanText($record['name'] ?? '');

        if ($supplierSku === '') {
            throw new InvalidArgumentException('SKU do fornecedor vazio.');
        }

        if (mb_strlen($supplierSku) > 100) {
            throw new InvalidArgumentException('SKU do fornecedor com mais de 100 caracteres.');
        }

        if ($name === '') {
            throw new InvalidArgumentException("Nome do produto vazio (SKU {$supplierSku}).");
        }

        if (mb_strlen($name) > 255) {
            throw new InvalidArgumentException("Nome do produto com mais de 255 caracteres (SKU {$supplierSku}).");
        }

        $cost = Decimal::normalize($record['cost_price'] ?? '');

        if ($cost === null || bccomp($cost, '0', 2) <= 0) {
            throw new InvalidArgumentException("Custo inválido para o SKU {$supplierSku}: \"{$record['cost_price']}\".");
        }

        $stock = $this->parseInteger($record['stock_quantity'] ?? '');

        if ($stock === null) {
            throw new InvalidArgumentException("Estoque inválido para o SKU {$supplierSku}: \"{$record['stock_quantity']}\".");
        }

        $ean = preg_replace('/\D+/', '', $record['ean'] ?? '') ?? '';

        if ($ean !== '' && (strlen($ean) < 8 || strlen($ean) > 14)) {
            throw new InvalidArgumentException("EAN inválido para o SKU {$supplierSku}: deve ter entre 8 e 14 dígitos.");
        }

        $shippingCost = null;

        if (trim($record['shipping_cost'] ?? '') !== '') {
            $shippingCost = Decimal::normalize($record['shipping_cost']);

            if ($shippingCost === null || bccomp($shippingCost, '0', 2) < 0) {
                throw new InvalidArgumentException("Frete inválido para o SKU {$supplierSku}.");
            }
        }

        $shippingDays = null;

        if (trim($record['shipping_days'] ?? '') !== '') {
            $shippingDays = $this->parseInteger($record['shipping_days']);

            if ($shippingDays === null || $shippingDays > 365) {
                throw new InvalidArgumentException("Prazo inválido para o SKU {$supplierSku}.");
            }
        }

        $brand      = $this->cleanText($record['brand'] ?? '');
        $productSku = $this->cleanText($record['product_sku'] ?? '');

        return new SupplierProductData(
            supplierSku: $supplierSku,
            name: $name,
            costPrice: $cost,
            stockQuantity: $stock,
            ean: $ean !== '' ? $ean : null,
            brand: $brand !== '' ? mb_substr($brand, 0, 100) : null,
            shippingCost: $shippingCost,
            shippingDays: $shippingDays,
            productSku: $productSku !== '' ? strtoupper(mb_substr($productSku, 0, 60)) : null,
        );
    }

    private function detectDelimiter(string $line): string
    {
        $candidates = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($candidates);

        return (string) array_key_first($candidates);
    }

    private function normalizeHeader(string $name): string
    {
        $name = $this->stripBom($name);
        $name = mb_strtolower(trim($name));
        $name = strtr($name, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $name), '_');
    }

    private function parseInteger(string $value): ?int
    {
        $value = trim($value);

        // Aceita "10", "10,0" e "10.00" (exportações de planilha).
        if (! preg_match('/^\d+([.,]0+)?$/', $value)) {
            return null;
        }

        $int = (int) preg_replace('/[.,]0+$/', '', $value);

        return $int <= 2_000_000_000 ? $int : null;
    }

    /**
     * Remove tags e caracteres de controle de textos vindos do fornecedor.
     */
    private function cleanText(string $value): string
    {
        $value = strip_tags($value);
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    private function toUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    /**
     * @param list<string|null> $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}

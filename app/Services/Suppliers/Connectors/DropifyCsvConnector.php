<?php

namespace App\Services\Suppliers\Connectors;

use App\DTO\Suppliers\ConnectionTestResult;
use App\DTO\Suppliers\DropifyCatalogItem;
use App\DTO\Suppliers\SupplierFeedResult;
use App\Support\Decimal;
use InvalidArgumentException;
use RuntimeException;
use SplFileObject;

/**
 * Parser do CSV exportado pela Dropify (UTF-8 com BOM, delimitador ; ou ,).
 *
 * Não chama a API Dropify. Mapeia por cabeçalho, não por posição.
 */
class DropifyCsvConnector extends AbstractSupplierConnector
{
    public const MAX_ROWS = 10000;

    public const REQUIRED_COLUMNS = ['sku', 'nome_do_produto', 'compre_por_r', 'estoque'];

    /**
     * @var array<string, list<string>>
     */
    public const HEADER_ALIASES = [
        'sku'                 => ['sku'],
        'nome_do_produto'     => ['nome_do_produto', 'nome', 'produto'],
        'variacoes'           => ['variacoes', 'variacoes'],
        'slug'                => ['slug'],
        'categoria'           => ['categoria'],
        'marca'               => ['marca'],
        'descricao_curta'     => ['descricao_curta'],
        'descricao'           => ['descricao'],
        'dados_tecnicos'      => ['dados_tecnicos'],
        'itens_inclusos'      => ['itens_inclusos'],
        'peso_g'              => ['peso_g', 'peso'],
        'altura_cm'           => ['altura_cm', 'altura'],
        'largura_cm'          => ['largura_cm', 'largura'],
        'comprimento_cm'      => ['comprimento_cm', 'comprimento'],
        'garantia_dias'       => ['garantia_dias', 'garantia'],
        'compre_por_r'        => ['compre_por_r', 'compre_por', 'custo', 'preco_custo'],
        'venda_por_r'         => ['venda_por_r', 'venda_por', 'preco_sugerido'],
        'estoque'             => ['estoque', 'stock_quantity', 'quantidade'],
        'prazo_de_postagem'   => ['prazo_de_postagem', 'prazo', 'prazo_postagem'],
        'unidade'             => ['unidade'],
        'ean'                 => ['ean', 'gtin'],
        'ncm'                 => ['ncm'],
        'origem_fiscal'       => ['origem_fiscal'],
        'cest'                => ['cest'],
        'status_de_disponibilidade' => ['status_de_disponibilidade', 'status'],
        'uf_de_origem'        => ['uf_de_origem', 'uf_origem', 'origem_uf'],
        'cep_de_origem'       => ['cep_de_origem', 'cep_origem'],
        'imagem_1'            => ['imagem_1'],
        'imagem_2'            => ['imagem_2'],
        'imagem_3'            => ['imagem_3'],
        'imagem_4'            => ['imagem_4'],
        'imagem_5'            => ['imagem_5'],
        'imagem_6'            => ['imagem_6'],
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
        if (! is_file($this->filePath) || ! is_readable($this->filePath)) {
            return ConnectionTestResult::fail('Arquivo CSV Dropify não encontrado ou sem permissão de leitura.');
        }

        return ConnectionTestResult::ok('Arquivo CSV Dropify disponível para leitura.');
    }

    public function fetchProducts(): SupplierFeedResult
    {
        $parsed = $this->parse();

        return new SupplierFeedResult(
            array_map(static fn (DropifyCatalogItem $item) => $item->toSupplierProductData(), $parsed['items']),
            $parsed['errors'],
            $parsed['total'],
        );
    }

    /**
     * @return array{items: list<DropifyCatalogItem>, errors: list<array{line: int, message: string}>, total: int}
     */
    public function parse(): array
    {
        if (! is_file($this->filePath) || ! is_readable($this->filePath)) {
            throw new RuntimeException('Arquivo CSV Dropify não encontrado ou sem permissão de leitura.');
        }

        $file = new SplFileObject($this->filePath, 'rb');
        $firstLine = (string) $file->fgets();
        $delimiter = $this->detectDelimiter($firstLine);
        $file->rewind();
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
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
                $errors[] = ['line' => $line, 'message' => 'Limite de ' . self::MAX_ROWS . ' linhas atingido; restante ignorado.'];
                $total--;

                break;
            }

            $record = [];
            foreach ($columns as $colIndex => $field) {
                $record[$field] = $row[$colIndex] ?? '';
            }

            try {
                $item = $this->buildItem($record);
            } catch (InvalidArgumentException $e) {
                $errors[] = ['line' => $line, 'message' => $e->getMessage()];

                continue;
            }

            $key = mb_strtolower($item->supplierSku);
            if (isset($seen[$key])) {
                $errors[] = ['line' => $line, 'message' => "SKU \"{$item->supplierSku}\" repetido (já na linha {$seen[$key]})."];

                continue;
            }
            $seen[$key] = $line;
            $items[]    = $item;
        }

        if ($columns === null) {
            throw new InvalidArgumentException('O arquivo CSV Dropify está vazio.');
        }

        return ['items' => $items, 'errors' => $errors, 'total' => $total];
    }

    /**
     * @param list<string> $header
     *
     * @return array<int, string>
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
            throw new InvalidArgumentException('Colunas obrigatórias ausentes no CSV Dropify: ' . implode(', ', $missing) . '.');
        }

        return $columns;
    }

    /**
     * @param array<string, string> $record
     */
    private function buildItem(array $record): DropifyCatalogItem
    {
        $sku  = $this->cleanText($record['sku'] ?? '');
        $name = $this->cleanText($record['nome_do_produto'] ?? '');
        if ($sku === '') {
            throw new InvalidArgumentException('SKU vazio.');
        }
        if (mb_strlen($sku) > 100) {
            throw new InvalidArgumentException('SKU com mais de 100 caracteres.');
        }
        if ($name === '' || mb_strlen($name) < 3) {
            throw new InvalidArgumentException("Nome inválido para o SKU {$sku}.");
        }

        $cost = Decimal::normalize($record['compre_por_r'] ?? '');
        if ($cost === null || bccomp($cost, '0', 2) <= 0) {
            throw new InvalidArgumentException("Custo inválido para o SKU {$sku}: \"{$record['compre_por_r']}\".");
        }

        $stock = $this->parseInteger($record['estoque'] ?? '');
        if ($stock === null) {
            throw new InvalidArgumentException("Estoque inválido para o SKU {$sku}.");
        }

        $suggested = null;
        if (trim($record['venda_por_r'] ?? '') !== '') {
            $suggested = Decimal::normalize($record['venda_por_r']);
            if ($suggested === null || bccomp($suggested, '0', 2) <= 0) {
                throw new InvalidArgumentException("Preço sugerido inválido para o SKU {$sku}.");
            }
        }

        $ean = preg_replace('/\D+/', '', $record['ean'] ?? '') ?? '';
        if ($ean !== '' && (strlen($ean) < 8 || strlen($ean) > 14)) {
            throw new InvalidArgumentException("EAN inválido para o SKU {$sku}.");
        }

        $weight = null;
        if (trim($record['peso_g'] ?? '') !== '') {
            $weight = $this->parseInteger($record['peso_g']);
            if ($weight === null) {
                throw new InvalidArgumentException("Peso inválido para o SKU {$sku}.");
            }
        }

        $shippingDays = null;
        if (trim($record['prazo_de_postagem'] ?? '') !== '') {
            $shippingDays = $this->parseInteger($record['prazo_de_postagem']);
            if ($shippingDays === null || $shippingDays > 365) {
                throw new InvalidArgumentException("Prazo inválido para o SKU {$sku}.");
            }
        }

        $images = [];
        foreach (['imagem_1', 'imagem_2', 'imagem_3', 'imagem_4', 'imagem_5', 'imagem_6'] as $imgField) {
            $url = trim($record[$imgField] ?? '');
            if ($url === '') {
                continue;
            }
            if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
                continue;
            }
            $images[] = mb_substr($url, 0, 500);
        }

        $uf = strtoupper(trim($record['uf_de_origem'] ?? ''));
        if ($uf !== '' && ! preg_match('/^[A-Z]{2}$/', $uf)) {
            throw new InvalidArgumentException("UF de origem inválida para o SKU {$sku}.");
        }

        $cep = preg_replace('/\D+/', '', $record['cep_de_origem'] ?? '') ?? '';
        if ($cep !== '' && strlen($cep) !== 8) {
            throw new InvalidArgumentException("CEP de origem inválido para o SKU {$sku}.");
        }

        $dim = static function (string $raw): ?string {
            $raw = trim($raw);
            if ($raw === '') {
                return null;
            }

            return Decimal::normalize($raw);
        };

        return new DropifyCatalogItem(
            supplierSku: $sku,
            name: mb_substr($name, 0, 200),
            costPrice: $cost,
            stockQuantity: $stock,
            suggestedPrice: $suggested,
            ean: $ean !== '' ? $ean : null,
            brand: ($b = $this->cleanText($record['marca'] ?? '')) !== '' ? mb_substr($b, 0, 100) : null,
            description: ($d = $this->cleanMultiline($record['descricao'] ?? '')) !== '' ? mb_substr($d, 0, 20000) : null,
            shortDescription: ($s = $this->cleanMultiline($record['descricao_curta'] ?? '')) !== '' ? mb_substr($s, 0, 2000) : null,
            sourceCategory: ($c = $this->cleanText($record['categoria'] ?? '')) !== '' ? mb_substr($c, 0, 190) : null,
            weightGrams: $weight,
            heightCm: $dim($record['altura_cm'] ?? ''),
            widthCm: $dim($record['largura_cm'] ?? ''),
            lengthCm: $dim($record['comprimento_cm'] ?? ''),
            shippingDays: $shippingDays,
            ncm: ($n = preg_replace('/\D+/', '', $record['ncm'] ?? '') ?? '') !== '' ? mb_substr($n, 0, 20) : null,
            cest: ($ce = preg_replace('/\D+/', '', $record['cest'] ?? '') ?? '') !== '' ? mb_substr($ce, 0, 20) : null,
            fiscalOrigin: ($fo = $this->cleanText($record['origem_fiscal'] ?? '')) !== '' ? mb_substr($fo, 0, 10) : null,
            originUf: $uf !== '' ? $uf : null,
            originPostalCode: $cep !== '' ? $cep : null,
            availabilityStatus: ($st = $this->cleanText($record['status_de_disponibilidade'] ?? '')) !== '' ? mb_substr($st, 0, 60) : null,
            imageUrls: $images,
            metadata: array_filter([
                'slug'           => $this->cleanText($record['slug'] ?? '') ?: null,
                'variacoes'      => $this->cleanText($record['variacoes'] ?? '') ?: null,
                'dados_tecnicos' => $this->cleanMultiline($record['dados_tecnicos'] ?? '') ?: null,
                'itens_inclusos' => $this->cleanMultiline($record['itens_inclusos'] ?? '') ?: null,
                'garantia_dias'  => $this->cleanText($record['garantia_dias'] ?? '') ?: null,
                'unidade'        => $this->cleanText($record['unidade'] ?? '') ?: null,
            ], static fn ($v) => $v !== null && $v !== ''),
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
        if (! preg_match('/^\d+([.,]0+)?$/', $value)) {
            return null;
        }
        $int = (int) preg_replace('/[.,]0+$/', '', $value);

        return $int <= 2_000_000_000 ? $int : null;
    }

    private function cleanText(string $value): string
    {
        $value = strip_tags($value);
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    private function cleanMultiline(string $value): string
    {
        $value = strip_tags($value);
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

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

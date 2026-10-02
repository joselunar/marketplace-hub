<?php

namespace App\DTO\Suppliers;

/**
 * Resultado da leitura de um feed de fornecedor (CSV, XML, API):
 * itens válidos + erros por linha/registro.
 */
final readonly class SupplierFeedResult
{
    /**
     * @param list<SupplierProductData>               $items
     * @param list<array{line: int, message: string}> $errors
     */
    public function __construct(
        public array $items,
        public array $errors,
        public int $totalRows,
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}

<?php

namespace App\DTO\Pricing;

use InvalidArgumentException;

/**
 * Custos por unidade que vêm do fornecedor e do produto.
 */
final class CostInput
{
    public function __construct(
        public readonly string $costPrice,
        public readonly string $shippingCost = '0.00',
        public readonly string $productExtraCost = '0.00',
    ) {
        foreach (['costPrice' => $costPrice, 'shippingCost' => $shippingCost, 'productExtraCost' => $productExtraCost] as $name => $value) {
            if (! is_numeric($value) || bccomp($value, '0', 2) < 0) {
                throw new InvalidArgumentException("Valor inválido para {$name}.");
            }
        }
    }
}

<?php

namespace App\Jobs;

use App\Services\Suppliers\SupplierSelectionService;

/**
 * Recalcula fornecedor principal e preço de todo o catálogo. A confiabilidade
 * dos fornecedores muda com o tempo (janela de dias), mesmo sem novos dados.
 */
class RecalculateSupplierSelectionJob extends AbstractJob
{
    public const TYPE = 'supplier_selection';

    public static function label(): string
    {
        return 'Recálculo de fornecedores e preços';
    }

    public function handle(array $payload): string
    {
        $stats = service('supplierSelection')->selectAll(SupplierSelectionService::TRIGGER_SCHEDULE);

        return sprintf('%d produto(s) avaliado(s), %d troca(s) de fornecedor principal.', $stats['evaluated'], $stats['changed']);
    }
}

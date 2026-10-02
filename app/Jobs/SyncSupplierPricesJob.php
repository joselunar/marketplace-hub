<?php

namespace App\Jobs;

use App\Services\Suppliers\SupplierSyncService;

class SyncSupplierPricesJob extends AbstractJob
{
    public const TYPE = 'supplier_prices';

    public static function label(): string
    {
        return 'Custos do fornecedor';
    }

    public function handle(array $payload): string
    {
        $stats = service('supplierSync')->sync($this->id($payload, 'supplier_id'), SupplierSyncService::MODE_PRICES);

        return sprintf('%d oferta(s) atualizada(s), %d SKU(s) desconhecido(s), %d erro(s) no retorno, %d troca(s) de fornecedor.', $stats['updated'], $stats['skipped'], $stats['errors'], $stats['changed']);
    }
}

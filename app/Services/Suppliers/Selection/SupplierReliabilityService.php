<?php

namespace App\Services\Suppliers\Selection;

use App\Models\SyncLogModel;
use App\Services\Suppliers\SupplierService;

/**
 * Confiabilidade do fornecedor a partir do histórico de sincronizações.
 *
 * Nota = 100 × (sucessos + metade dos alertas) / total, dentro da janela
 * configurada. Fornecedores sem sincronizações no período não aparecem no
 * resultado e o ranking usa a nota padrão.
 */
class SupplierReliabilityService
{
    public function __construct(
        private readonly SyncLogModel $syncLogs = new SyncLogModel(),
    ) {
    }

    /**
     * @return array<int, array{score: float, total: int, errors: int, warnings: int}> por supplier_id
     */
    public function scores(int $windowDays): array
    {
        $rows = $this->syncLogs->builder()
            ->select('integration_id, COUNT(*) AS total')
            ->select("SUM(status = '" . SyncLogModel::STATUS_ERROR . "') AS errors", false)
            ->select("SUM(status = '" . SyncLogModel::STATUS_WARNING . "') AS warnings", false)
            ->where('integration_type', SupplierService::SYNC_INTEGRATION_TYPE)
            ->where('integration_id IS NOT NULL', null, false)
            ->where('started_at >=', date('Y-m-d H:i:s', strtotime("-{$windowDays} days")))
            ->groupBy('integration_id')
            ->get()
            ->getResultArray();

        $scores = [];

        foreach ($rows as $row) {
            $total    = (int) $row['total'];
            $errors   = (int) $row['errors'];
            $warnings = (int) $row['warnings'];

            $scores[(int) $row['integration_id']] = [
                'score'    => round(($total - $errors - $warnings / 2) / $total * 100, 2),
                'total'    => $total,
                'errors'   => $errors,
                'warnings' => $warnings,
            ];
        }

        return $scores;
    }
}

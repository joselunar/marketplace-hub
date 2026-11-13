<?php

namespace App\Models;

use CodeIgniter\Model;

class ProfitabilityAnalysisHistoryModel extends Model
{
    protected $table         = 'profitability_analysis_history';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'product_id', 'analysis_id', 'classification', 'sale_price',
        'contribution_margin_pct', 'cost_reliability', 'calculation_source',
        'snapshot', 'created_by', 'created_at',
    ];

    public function forProduct(int $productId, int $limit = 20): array
    {
        return $this->where('product_id', $productId)
            ->orderBy('id', 'DESC')
            ->findAll($limit);
    }
}

<?php

namespace App\Models;

use CodeIgniter\Model;

class CommercialIntelligenceModel extends Model
{
    protected $table         = 'commercial_intelligence';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'product_id', 'targets_json', 'competition_json', 'score_json',
        'data_reliability', 'quoted_at', 'created_by',
    ];

    public function findForProduct(int $productId): ?array
    {
        return $this->where('product_id', $productId)->first();
    }
}

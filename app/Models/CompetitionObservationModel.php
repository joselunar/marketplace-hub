<?php

namespace App\Models;

use CodeIgniter\Model;

class CompetitionObservationModel extends Model
{
    protected $table         = 'competition_observations';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'product_id', 'source', 'match_type', 'external_item_id', 'external_title',
        'external_price', 'currency_id', 'free_shipping', 'permalink', 'seller_nickname',
        'equivalence_note', 'source_url', 'raw_payload', 'captured_at', 'created_by',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function latestForProduct(int $productId, int $limit = 40): array
    {
        return $this->where('product_id', $productId)
            ->orderBy('captured_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll($limit);
    }
}

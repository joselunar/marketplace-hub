<?php

namespace App\Models;

use CodeIgniter\Model;

class MarketplaceCategoryMappingModel extends Model
{
    protected $table         = 'marketplace_category_mappings';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['marketplace_id', 'category_id', 'external_category_id', 'external_category_name'];

    public function findFor(int $marketplaceId, int $categoryId): ?array
    {
        return $this->where('marketplace_id', $marketplaceId)->where('category_id', $categoryId)->first();
    }
}

<?php

namespace App\Models;

use CodeIgniter\Model;

class SupplierCategoryMappingModel extends Model
{
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_SUGGESTED = 'suggested';

    protected $table         = 'supplier_category_mappings';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'marketplace_id', 'provider_code', 'source_category',
        'external_category_id', 'external_category_name',
        'status', 'origin', 'confirmed_by', 'confirmed_at',
    ];

    public function findConfirmed(int $marketplaceId, string $providerCode, string $sourceCategory): ?array
    {
        $sourceCategory = trim($sourceCategory);
        if ($sourceCategory === '') {
            return null;
        }

        return $this->where('marketplace_id', $marketplaceId)
            ->where('provider_code', $providerCode)
            ->where('source_category', $sourceCategory)
            ->where('status', self::STATUS_CONFIRMED)
            ->first();
    }
}

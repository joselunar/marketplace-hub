<?php

namespace App\Models;

use CodeIgniter\Model;

class MarketplaceModel extends Model
{
    protected $table         = 'marketplaces';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['name', 'slug', 'active'];

    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->first();
    }
}

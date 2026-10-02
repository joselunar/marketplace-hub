<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductImageModel extends Model
{
    protected $table         = 'product_images';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $updatedField  = '';
    protected $allowedFields = ['product_id', 'url', 'position'];

    public function forProduct(int $productId): array
    {
        return $this->where('product_id', $productId)->orderBy('position')->findAll();
    }
}

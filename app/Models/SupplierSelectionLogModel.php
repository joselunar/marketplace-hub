<?php

namespace App\Models;

use CodeIgniter\Model;

class SupplierSelectionLogModel extends Model
{
    protected $table         = 'supplier_selection_logs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $updatedField  = '';
    protected $allowedFields = [
        'product_id', 'previous_offer_id', 'previous_supplier_name', 'previous_score',
        'new_offer_id', 'new_supplier_name', 'new_score', 'reason', 'trigger', 'user_id', 'ranking',
    ];

    public function forProduct(int $productId, int $limit = 10): array
    {
        return $this->select('supplier_selection_logs.*, users.name AS user_name')
            ->join('users', 'users.id = supplier_selection_logs.user_id', 'left')
            ->where('supplier_selection_logs.product_id', $productId)
            ->orderBy('supplier_selection_logs.id', 'DESC')
            ->findAll($limit);
    }
}

<?php

namespace App\Models;

use CodeIgniter\Model;

class SupplierImportModel extends Model
{
    public const STATUS_PROCESSING            = 'processing';
    public const STATUS_COMPLETED             = 'completed';
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    public const STATUS_FAILED                = 'failed';

    protected $table         = 'supplier_imports';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'supplier_id', 'user_id', 'source', 'original_filename', 'status', 'total_rows',
        'created_count', 'updated_count', 'linked_count', 'error_count', 'errors', 'started_at', 'finished_at',
    ];

    public function withRelations(): static
    {
        $this->select('supplier_imports.*, suppliers.name AS supplier_name, users.name AS user_name')
            ->join('suppliers', 'suppliers.id = supplier_imports.supplier_id')
            ->join('users', 'users.id = supplier_imports.user_id', 'left');

        return $this;
    }
}

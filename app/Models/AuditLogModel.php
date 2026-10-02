<?php

namespace App\Models;

use CodeIgniter\Model;

class AuditLogModel extends Model
{
    protected $table          = 'audit_logs';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $updatedField   = '';
    protected $allowedFields  = ['user_id', 'action', 'entity_type', 'entity_id', 'old_values', 'new_values', 'ip_address'];

    public function withUser(): static
    {
        $this->select('audit_logs.*, users.name AS user_name')
            ->join('users', 'users.id = audit_logs.user_id', 'left');

        return $this;
    }
}

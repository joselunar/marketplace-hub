<?php

namespace App\Models;

use CodeIgniter\Model;

class FiscalCertificateModel extends Model
{
    protected $table         = 'fiscal_certificates';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'fiscal_company_id', 'label', 'storage_path', 'password_encrypted',
        'subject', 'serial_number', 'valid_from', 'valid_to', 'active', 'created_by',
    ];

    public function activeForCompany(int $companyId): ?array
    {
        return $this->where('fiscal_company_id', $companyId)
            ->where('active', 1)
            ->orderBy('id', 'DESC')
            ->first();
    }
}

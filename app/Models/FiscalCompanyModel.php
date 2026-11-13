<?php

namespace App\Models;

use CodeIgniter\Model;

class FiscalCompanyModel extends Model
{
    protected $table         = 'fiscal_companies';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'legal_name', 'trade_name', 'document', 'state_registration', 'municipal_registration',
        'crt', 'tax_regime_note', 'zip_code', 'street', 'number', 'complement', 'district',
        'city', 'city_ibge', 'uf', 'phone', 'email', 'serie_nfe', 'next_number_nfe',
        'tax_params_json', 'tax_params_validated', 'tax_params_validated_at', 'tax_params_validated_by',
    ];

    public function primaryCompany(): ?array
    {
        return $this->orderBy('id', 'ASC')->first();
    }
}

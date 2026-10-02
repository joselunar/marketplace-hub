<?php

namespace App\Models;

use CodeIgniter\Model;

class MarketplaceModel extends Model
{
    /**
     * Colunas de tarifa; vazias herdam das configurações de precificação.
     */
    public const FEE_FIELDS = [
        'fee_percentage'      => 'Comissão (%)',
        'fixed_fee'           => 'Tarifa fixa (R$)',
        'fixed_fee_threshold' => 'Tarifa fixa abaixo de (R$)',
    ];

    protected $table         = 'marketplaces';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['name', 'slug', 'active', 'fee_percentage', 'fixed_fee', 'fixed_fee_threshold'];

    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->first();
    }
}

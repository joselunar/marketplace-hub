<?php

namespace App\Models;

use CodeIgniter\Model;

class ShippingOriginModel extends Model
{
    public const SOURCE_DOCUMENTAL = 'documental';
    public const SOURCE_FORNECEDOR = 'fornecedor';
    public const SOURCE_MANUAL     = 'manual';
    public const SOURCE_ESTIMADO   = 'estimado';

    public const SOURCE_LABELS = [
        self::SOURCE_DOCUMENTAL => 'Confirmado documentalmente',
        self::SOURCE_FORNECEDOR => 'Informado pelo fornecedor',
        self::SOURCE_MANUAL     => 'Registro manual',
        self::SOURCE_ESTIMADO   => 'Estimado (não usar como frete real)',
    ];

    protected $table         = 'shipping_origins';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'supplier_id', 'product_id', 'label', 'postal_code', 'city', 'uf',
        'source_type', 'evidence_note', 'evidence_url', 'confirmed_at', 'active', 'created_by',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function forSupplier(int $supplierId, bool $onlyActive = true): array
    {
        $builder = $this->where('supplier_id', $supplierId)->where('product_id', null);
        if ($onlyActive) {
            $builder->where('active', 1);
        }

        return $builder->orderBy('confirmed_at', 'DESC')->orderBy('id', 'DESC')->findAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forProduct(int $productId, bool $onlyActive = true): array
    {
        $builder = $this->where('product_id', $productId);
        if ($onlyActive) {
            $builder->where('active', 1);
        }

        return $builder->orderBy('confirmed_at', 'DESC')->orderBy('id', 'DESC')->findAll();
    }
}

<?php

namespace App\Models;

use CodeIgniter\Model;

class HomologationChecklistItemModel extends Model
{
    public const STATUS_PENDING   = 'pendente';
    public const STATUS_CONFIRMED = 'confirmado';
    public const STATUS_DENIED    = 'negado';

    public const STATUS_LABELS = [
        self::STATUS_PENDING   => 'Pendente',
        self::STATUS_CONFIRMED => 'Confirmado',
        self::STATUS_DENIED    => 'Negado',
    ];

    protected $table         = 'homologation_checklist_items';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'supplier_id', 'provider_code', 'item_code', 'label', 'status',
        'evidence_note', 'sort_order', 'updated_by',
    ];
}

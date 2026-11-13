<?php

namespace App\Models;

use CodeIgniter\Model;

class FiscalInvoiceEventModel extends Model
{
    protected $table         = 'fiscal_invoice_events';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'fiscal_invoice_id', 'event_type', 'message', 'payload_json', 'created_by',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function forInvoice(int $invoiceId, int $limit = 50): array
    {
        return $this->where('fiscal_invoice_id', $invoiceId)
            ->orderBy('id', 'DESC')
            ->findAll($limit);
    }
}

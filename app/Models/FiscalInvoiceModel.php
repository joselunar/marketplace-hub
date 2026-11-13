<?php

namespace App\Models;

use CodeIgniter\Model;

class FiscalInvoiceModel extends Model
{
    public const STATUS_DRAFT        = 'rascunho';
    public const STATUS_SIGNED       = 'assinada';
    public const STATUS_SENT         = 'enviada';
    public const STATUS_AUTHORIZED   = 'autorizada';
    public const STATUS_REJECTED     = 'rejeitada';
    public const STATUS_DENIED       = 'denegada';
    public const STATUS_CANCELLED    = 'cancelada';
    public const STATUS_WAITING      = 'aguardando';
    public const STATUS_ERROR        = 'erro';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT      => 'Rascunho',
        self::STATUS_SIGNED     => 'Assinada',
        self::STATUS_SENT       => 'Enviada',
        self::STATUS_AUTHORIZED => 'Autorizada',
        self::STATUS_REJECTED   => 'Rejeitada',
        self::STATUS_DENIED     => 'Denegada',
        self::STATUS_CANCELLED  => 'Cancelada',
        self::STATUS_WAITING    => 'Aguardando',
        self::STATUS_ERROR      => 'Erro',
    ];

    protected $table         = 'fiscal_invoices';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'fiscal_company_id', 'order_id', 'marketplace_account_id', 'external_order_id',
        'shipping_id', 'pack_id', 'model', 'serie', 'number', 'access_key', 'environment',
        'status', 'sefaz_status_code', 'sefaz_message', 'protocol', 'receipt',
        'issued_at', 'authorized_at', 'cancelled_at', 'xml_signed_path', 'xml_authorized_path',
        'danfe_path', 'payload_json', 'dedupe_key', 'ml_send_status', 'ml_send_message',
        'ml_sent_at', 'created_by',
    ];

    public function countsByStatus(): array
    {
        $rows = $this->select('status, COUNT(*) AS total')->groupBy('status')->findAll();
        $out  = [];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['total'];
        }

        return $out;
    }

    public function applyFilters(array $filters): static
    {
        if (! empty($filters['status'])) {
            $this->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            $this->groupStart()
                ->like('access_key', $q)
                ->orLike('external_order_id', $q)
                ->orLike('shipping_id', $q)
                ->orWhere('number', ctype_digit($q) ? (int) $q : -1)
                ->groupEnd();
        }
        if (! empty($filters['date_from'])) {
            $this->where('issued_at >=', $filters['date_from'] . ' 00:00:00');
        }
        if (! empty($filters['date_to'])) {
            $this->where('issued_at <=', $filters['date_to'] . ' 23:59:59');
        }

        return $this;
    }
}

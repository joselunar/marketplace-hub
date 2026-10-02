<?php

namespace App\Models;

use CodeIgniter\Model;

class WebhookEventModel extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED  = 'processed';
    public const STATUS_IGNORED    = 'ignored';
    public const STATUS_FAILED     = 'failed';

    public const STATUS_LABELS = [
        self::STATUS_PENDING    => 'Na fila',
        self::STATUS_PROCESSING => 'Processando',
        self::STATUS_PROCESSED  => 'Processada',
        self::STATUS_IGNORED    => 'Ignorada',
        self::STATUS_FAILED     => 'Falhou',
    ];

    protected $table         = 'webhook_events';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'provider', 'marketplace_account_id', 'notification_id', 'dedupe_key', 'topic', 'resource', 'user_id',
        'application_id', 'attempts', 'status', 'process_attempts', 'message', 'payload', 'sent_at',
        'received_at', 'locked_at', 'processed_at',
    ];

    public function withAccount(): static
    {
        $this->select('webhook_events.*, marketplace_accounts.name AS account_name')
            ->join('marketplace_accounts', 'marketplace_accounts.id = webhook_events.marketplace_account_id', 'left');

        return $this;
    }

    /**
     * @param array{status?: string, topic?: string, q?: string} $filters
     */
    public function applyFilters(array $filters): static
    {
        if (! empty($filters['status']) && array_key_exists($filters['status'], self::STATUS_LABELS)) {
            $this->where('webhook_events.status', $filters['status']);
        }

        if (! empty($filters['topic'])) {
            $this->where('webhook_events.topic', $filters['topic']);
        }

        if (! empty($filters['q'])) {
            $this->like('webhook_events.resource', $filters['q']);
        }

        return $this;
    }

    /**
     * @return array<string, int> quantidade por status
     */
    public function countsByStatus(): array
    {
        $rows = $this->builder()->select('status, COUNT(*) AS total')->groupBy('status')->get()->getResultArray();

        return array_map('intval', array_column($rows, 'total', 'status'));
    }
}

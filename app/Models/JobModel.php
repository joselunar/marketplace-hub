<?php

namespace App\Models;

use CodeIgniter\Model;

class JobModel extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_PENDING   => 'Na fila',
        self::STATUS_RUNNING   => 'Executando',
        self::STATUS_COMPLETED => 'Concluído',
        self::STATUS_FAILED    => 'Falhou',
        self::STATUS_CANCELLED => 'Cancelado',
    ];

    // Origem do job.
    public const SOURCE_SCHEDULE = 'schedule';
    public const SOURCE_EVENT    = 'event';
    public const SOURCE_MANUAL   = 'manual';
    public const SOURCE_COMMAND  = 'command';

    public const SOURCE_LABELS = [
        self::SOURCE_SCHEDULE => 'Agendador',
        self::SOURCE_EVENT    => 'Alteração detectada',
        self::SOURCE_MANUAL   => 'Painel',
        self::SOURCE_COMMAND  => 'Linha de comando',
    ];

    protected $table         = 'jobs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'type', 'payload', 'unique_key', 'reference', 'status', 'attempts', 'max_attempts', 'available_at',
        'started_at', 'finished_at', 'result', 'last_error', 'source', 'created_by',
    ];

    public function withUser(): static
    {
        $this->select('jobs.*, users.name AS created_by_name')->join('users', 'users.id = jobs.created_by', 'left');

        return $this;
    }

    /**
     * @param array{status?: string, type?: string, q?: string} $filters
     */
    public function applyFilters(array $filters): static
    {
        if (! empty($filters['status']) && array_key_exists($filters['status'], self::STATUS_LABELS)) {
            $this->where('jobs.status', $filters['status']);
        }
        if (! empty($filters['type'])) {
            $this->where('jobs.type', $filters['type']);
        }
        if (! empty($filters['q'])) {
            $this->like('jobs.reference', $filters['q']);
        }

        return $this;
    }

    /**
     * @return array<string, int>
     */
    public function countsByStatus(?string $since = null): array
    {
        $builder = $this->builder()->select('status, COUNT(*) AS total')->groupBy('status');
        if ($since !== null) {
            $builder->groupStart()
                ->whereIn('status', [self::STATUS_PENDING, self::STATUS_RUNNING])
                ->orWhere('updated_at >=', $since)
                ->groupEnd();
        }

        return array_map('intval', array_column($builder->get()->getResultArray(), 'total', 'status'));
    }
}

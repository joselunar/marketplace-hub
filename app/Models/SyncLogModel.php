<?php

namespace App\Models;

use CodeIgniter\Model;

class SyncLogModel extends Model
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR   = 'error';

    protected $table         = 'sync_logs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['integration_type', 'integration_id', 'action', 'status', 'message', 'payload', 'started_at', 'finished_at'];

    public function latestFor(string $integrationType, int $integrationId): ?array
    {
        return $this->where('integration_type', $integrationType)
            ->where('integration_id', $integrationId)
            ->orderBy('started_at', 'DESC')
            ->first();
    }

    public function recentErrorsFor(string $integrationType, int $integrationId, int $limit = 5): array
    {
        return $this->where('integration_type', $integrationType)
            ->where('integration_id', $integrationId)
            ->whereIn('status', [self::STATUS_ERROR, self::STATUS_WARNING])
            ->orderBy('started_at', 'DESC')
            ->findAll($limit);
    }
}

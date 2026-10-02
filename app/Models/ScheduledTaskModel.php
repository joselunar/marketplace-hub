<?php

namespace App\Models;

use CodeIgniter\Model;

class ScheduledTaskModel extends Model
{
    // Registros de controle, além das tarefas de Config\Automation.
    public const SCHEDULER = 'scheduler';
    public const WORKER    = 'worker';

    protected $table            = 'scheduled_tasks';
    protected $primaryKey       = 'name';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $allowedFields    = ['name', 'last_run_at', 'last_status', 'last_message', 'updated_at'];

    public function record(string $name, string $status, string $message, ?string $at = null): void
    {
        $at ??= date('Y-m-d H:i:s');

        $this->db->query(
            'INSERT INTO scheduled_tasks (name, last_run_at, last_status, last_message, updated_at) VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at), last_status = VALUES(last_status), last_message = VALUES(last_message), updated_at = VALUES(updated_at)',
            [$name, $at, $status, mb_substr($message, 0, 500), $at],
        );
    }

    /**
     * @return array<string, array<string, mixed>> nome => linha
     */
    public function allByName(): array
    {
        return array_column($this->findAll(), null, 'name');
    }
}

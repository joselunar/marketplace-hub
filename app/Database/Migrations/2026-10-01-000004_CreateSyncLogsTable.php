<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSyncLogsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'               => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'integration_type' => ['type' => 'VARCHAR', 'constraint' => 30],
            'integration_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'action'           => ['type' => 'VARCHAR', 'constraint' => 60],
            'status'           => ['type' => 'VARCHAR', 'constraint' => 20],
            'message'          => ['type' => 'TEXT', 'null' => true],
            'payload'          => ['type' => 'JSON', 'null' => true],
            'started_at'       => ['type' => 'DATETIME', 'null' => true],
            'finished_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['integration_type', 'integration_id']);
        $this->forge->addKey('status');
        $this->forge->addKey('started_at');
        $this->forge->createTable('sync_logs', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('sync_logs', true);
    }
}

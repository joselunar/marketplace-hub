<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAutomationTables extends Migration
{
    public function up(): void
    {
        // Fila de jobs processada por "php spark jobs:work". unique_key evita
        // dois jobs iguais na fila: é preenchido só enquanto o job está pendente.
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'type'         => ['type' => 'VARCHAR', 'constraint' => 60],
            'payload'      => ['type' => 'VARCHAR', 'constraint' => 1000],
            'unique_key'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'reference'    => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
            'attempts'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'max_attempts' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 5],
            'available_at' => ['type' => 'DATETIME'],
            'started_at'   => ['type' => 'DATETIME', 'null' => true],
            'finished_at'  => ['type' => 'DATETIME', 'null' => true],
            'result'       => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'last_error'   => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'source'       => ['type' => 'VARCHAR', 'constraint' => 30],
            'created_by'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('unique_key');
        $this->forge->addKey(['status', 'available_at']);
        $this->forge->addKey(['type', 'status']);
        $this->forge->addKey('created_at');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('jobs', false, ['ENGINE' => 'InnoDB']);

        // Última execução de cada tarefa agendada (e do agendador e do worker).
        $this->forge->addField([
            'name'         => ['type' => 'VARCHAR', 'constraint' => 60],
            'last_run_at'  => ['type' => 'DATETIME', 'null' => true],
            'last_status'  => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'last_message' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('name');
        $this->forge->createTable('scheduled_tasks', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('scheduled_tasks', true);
        $this->forge->dropTable('jobs', true);
    }
}

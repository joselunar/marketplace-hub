<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSettingsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'key'        => ['type' => 'VARCHAR', 'constraint' => 100],
            'value'      => ['type' => 'TEXT', 'null' => true],
            'type'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'string'],
            'group'      => ['type' => 'VARCHAR', 'constraint' => 50, 'default' => 'general'],
            'updated_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('key');
        $this->forge->addKey('group');
        $this->forge->addForeignKey('updated_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('settings', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('settings', true);
    }
}

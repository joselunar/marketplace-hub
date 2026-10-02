<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSupplierImportsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'supplier_id'       => ['type' => 'INT', 'unsigned' => true],
            'user_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'source'            => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'csv'],
            'original_filename' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'processing'],
            'total_rows'        => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'created_count'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'updated_count'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'linked_count'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'error_count'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'errors'            => ['type' => 'JSON', 'null' => true],
            'started_at'        => ['type' => 'DATETIME', 'null' => true],
            'finished_at'       => ['type' => 'DATETIME', 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('status');
        $this->forge->addKey('created_at');
        $this->forge->addForeignKey('supplier_id', 'suppliers', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('supplier_imports', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('supplier_imports', true);
    }
}

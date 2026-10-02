<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSuppliersTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'                  => ['type' => 'VARCHAR', 'constraint' => 120],
            'legal_name'            => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'document'              => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'email'                 => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'phone'                 => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'website'               => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'integration_type'      => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'manual'],
            'status'                => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
            'priority'              => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 5],
            'default_shipping_days' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'notes'                 => ['type' => 'TEXT', 'null' => true],
            'created_at'            => ['type' => 'DATETIME', 'null' => true],
            'updated_at'            => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('document');
        $this->forge->addKey('status');
        $this->forge->addKey('integration_type');
        $this->forge->addKey('created_at');
        $this->forge->createTable('suppliers', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('suppliers', true);
    }
}

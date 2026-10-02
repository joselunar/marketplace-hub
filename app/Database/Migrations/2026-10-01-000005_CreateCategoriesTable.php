<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCategoriesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'parent_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 120],
            'slug'        => ['type' => 'VARCHAR', 'constraint' => 140],
            'description' => ['type' => 'TEXT', 'null' => true],
            'active'      => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('active');
        $this->forge->addForeignKey('parent_id', 'categories', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('categories', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('categories', true);
    }
}

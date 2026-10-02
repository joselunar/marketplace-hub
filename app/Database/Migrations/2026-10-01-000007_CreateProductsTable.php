<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateProductsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'sku'         => ['type' => 'VARCHAR', 'constraint' => 60],
            'ean'         => ['type' => 'VARCHAR', 'constraint' => 14, 'null' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 200],
            'slug'        => ['type' => 'VARCHAR', 'constraint' => 220],
            'description' => ['type' => 'TEXT', 'null' => true],
            'brand'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'category_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            // Peso em kg e dimensões em cm.
            'weight'     => ['type' => 'DECIMAL', 'constraint' => '10,3', 'null' => true],
            'height'     => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'width'      => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'length'     => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'active'     => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('sku');
        $this->forge->addUniqueKey('ean');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('brand');
        $this->forge->addKey('active');
        $this->forge->addKey('created_at');
        $this->forge->addForeignKey('category_id', 'categories', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('products', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('products', true);
    }
}

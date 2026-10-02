<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateProductImagesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'product_id' => ['type' => 'INT', 'unsigned' => true],
            'url'        => ['type' => 'VARCHAR', 'constraint' => 500],
            'position'   => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['product_id', 'position']);
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('product_images', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('product_images', true);
    }
}

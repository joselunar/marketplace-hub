<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Oferta de um produto em um fornecedor.
 *
 * product_id é opcional: ofertas importadas que ainda não foram associadas
 * ao catálogo interno ficam "sem vínculo" até alguém relacioná-las.
 */
class CreateSupplierOffersTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'supplier_id'           => ['type' => 'INT', 'unsigned' => true],
            'product_id'            => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'supplier_sku'          => ['type' => 'VARCHAR', 'constraint' => 100],
            'supplier_product_name' => ['type' => 'VARCHAR', 'constraint' => 255],
            'ean'                   => ['type' => 'VARCHAR', 'constraint' => 14, 'null' => true],
            'cost_price'            => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => '0.00'],
            'stock_quantity'        => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'shipping_cost'         => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => '0.00'],
            'shipping_days'         => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'active'                => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 1],
            'last_sync_at'          => ['type' => 'DATETIME', 'null' => true],
            'created_at'            => ['type' => 'DATETIME', 'null' => true],
            'updated_at'            => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['supplier_id', 'supplier_sku']);
        $this->forge->addKey('supplier_sku');
        $this->forge->addKey('ean');
        $this->forge->addKey('active');
        $this->forge->addForeignKey('supplier_id', 'suppliers', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('supplier_offers', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('supplier_offers', true);
    }
}

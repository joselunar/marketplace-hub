<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Categoria MLB no produto e mapeamento persistente fornecedor → MLB.
 * Somente aditivo.
 */
class AddMlCategoryMappingForProfitability extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('products', [
            'marketplace_category_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'after'      => 'source_provider',
                'comment'    => 'Categoria MLB confirmada ou sugerida para cotação.',
            ],
            'marketplace_category_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
                'after'      => 'marketplace_category_id',
            ],
            'marketplace_category_source' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
                'after'      => 'marketplace_category_name',
                'comment'    => 'suggested|confirmed|manual|supplier_mapping',
            ],
            'marketplace_category_confirmed_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'marketplace_category_source',
            ],
        ]);
        $this->db->query('ALTER TABLE products ADD INDEX products_marketplace_category_id (marketplace_category_id)');

        $this->forge->addField([
            'id'                     => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'marketplace_id'         => ['type' => 'INT', 'unsigned' => true],
            'provider_code'          => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'dropify'],
            'source_category'        => ['type' => 'VARCHAR', 'constraint' => 190],
            'external_category_id'   => ['type' => 'VARCHAR', 'constraint' => 20],
            'external_category_name' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'status'                 => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'confirmed'],
            'origin'                 => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'manual'],
            'confirmed_by'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'confirmed_at'           => ['type' => 'DATETIME', 'null' => true],
            'created_at'             => ['type' => 'DATETIME', 'null' => true],
            'updated_at'             => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['marketplace_id', 'provider_code', 'source_category'], 'supplier_cat_map_unique');
        $this->forge->addKey('external_category_id');
        $this->forge->addForeignKey('marketplace_id', 'marketplaces', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('confirmed_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('supplier_category_mappings', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('supplier_category_mappings', true);
        $this->forge->dropColumn('products', [
            'marketplace_category_id',
            'marketplace_category_name',
            'marketplace_category_source',
            'marketplace_category_confirmed_at',
        ]);
    }
}

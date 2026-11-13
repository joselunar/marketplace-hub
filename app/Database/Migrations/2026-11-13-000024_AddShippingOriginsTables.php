<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CEPs de expedição por fornecedor/CD e, opcionalmente, por SKU/produto.
 * Somente aditivo.
 */
class AddShippingOriginsTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'supplier_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'product_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'label'           => ['type' => 'VARCHAR', 'constraint' => 120],
            'postal_code'     => ['type' => 'VARCHAR', 'constraint' => 12],
            'city'            => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'uf'              => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            'source_type'     => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'documental'],
            'evidence_note'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'evidence_url'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'confirmed_at'    => ['type' => 'DATETIME', 'null' => true],
            'active'          => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['supplier_id', 'active']);
        $this->forge->addKey(['product_id', 'active']);
        $this->forge->addKey('postal_code');
        $this->forge->addForeignKey('supplier_id', 'suppliers', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('shipping_origins', false, ['ENGINE' => 'InnoDB']);

        if (! $this->db->fieldExists('origin_source', 'supplier_offers')) {
            $this->forge->addColumn('supplier_offers', [
                'origin_source' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 30,
                    'null'       => true,
                    'after'      => 'origin_postal_code',
                ],
                'origin_evidence' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 500,
                    'null'       => true,
                    'after'      => 'origin_source',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('origin_evidence', 'supplier_offers')) {
            $this->forge->dropColumn('supplier_offers', 'origin_evidence');
        }
        if ($this->db->fieldExists('origin_source', 'supplier_offers')) {
            $this->forge->dropColumn('supplier_offers', 'origin_source');
        }
        $this->forge->dropTable('shipping_origins', true);
    }
}

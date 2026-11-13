<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Inteligência comercial: snapshots de concorrência e simulações de preço-alvo.
 * Somente aditivo.
 */
class AddCommercialIntelligenceTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'product_id'         => ['type' => 'INT', 'unsigned' => true],
            'source'             => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'api'],
            'match_type'         => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'external_item_id'   => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'external_title'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'external_price'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'currency_id'        => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'free_shipping'      => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'permalink'          => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'seller_nickname'    => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'equivalence_note'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'source_url'         => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'raw_payload'        => ['type' => 'JSON', 'null' => true],
            'captured_at'        => ['type' => 'DATETIME', 'null' => true],
            'created_by'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
            'updated_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['product_id', 'captured_at']);
        $this->forge->addKey('external_item_id');
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('competition_observations', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id'               => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'product_id'       => ['type' => 'INT', 'unsigned' => true],
            'targets_json'     => ['type' => 'JSON'],
            'competition_json' => ['type' => 'JSON', 'null' => true],
            'score_json'       => ['type' => 'JSON', 'null' => true],
            'data_reliability' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'estimado'],
            'quoted_at'        => ['type' => 'DATETIME', 'null' => true],
            'created_by'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('product_id');
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('commercial_intelligence', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('commercial_intelligence', true);
        $this->forge->dropTable('competition_observations', true);
    }
}

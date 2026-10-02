<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMarketplaceListingTables extends Migration
{
    public function up(): void
    {
        // Um anúncio por produto e conta. Valores em DECIMAL; tarifas são as
        // devolvidas pela API (listing_prices) no momento da publicação.
        $this->forge->addField([
            'id'                     => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'marketplace_account_id' => ['type' => 'INT', 'unsigned' => true],
            'product_id'             => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'external_listing_id'    => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'external_product_id'    => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'selling_model'          => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'classic'],
            'title'                  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'family_name'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'category_id'            => ['type' => 'VARCHAR', 'constraint' => 20],
            'listing_type'           => ['type' => 'VARCHAR', 'constraint' => 30],
            'item_condition'         => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'new'],
            'currency_id'            => ['type' => 'VARCHAR', 'constraint' => 5],
            'price'                  => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'available_quantity'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'                 => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'pending'],
            'sub_status'             => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'permalink'              => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'sale_fee_percentage'    => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'sale_fixed_fee'         => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'sale_fee_amount'        => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'listing_fee_amount'     => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'attributes'             => ['type' => 'TEXT', 'null' => true],
            'last_error'             => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'published_by'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'published_at'           => ['type' => 'DATETIME', 'null' => true],
            'last_sync_at'           => ['type' => 'DATETIME', 'null' => true],
            'created_at'             => ['type' => 'DATETIME', 'null' => true],
            'updated_at'             => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['marketplace_account_id', 'product_id']);
        $this->forge->addUniqueKey(['marketplace_account_id', 'external_listing_id']);
        $this->forge->addKey('status');
        $this->forge->addForeignKey('marketplace_account_id', 'marketplace_accounts', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('published_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('marketplace_listings', false, ['ENGINE' => 'InnoDB']);

        // Histórico de cada operação feita no anúncio (publicação, preço, estoque, status, sincronização).
        $this->forge->addField([
            'id'                     => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'marketplace_listing_id' => ['type' => 'INT', 'unsigned' => true],
            'action'                 => ['type' => 'VARCHAR', 'constraint' => 30],
            'status'                 => ['type' => 'VARCHAR', 'constraint' => 20],
            'message'                => ['type' => 'VARCHAR', 'constraint' => 1000],
            'old_values'             => ['type' => 'TEXT', 'null' => true],
            'new_values'             => ['type' => 'TEXT', 'null' => true],
            'user_id'                => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'             => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['marketplace_listing_id', 'created_at']);
        $this->forge->addForeignKey('marketplace_listing_id', 'marketplace_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('marketplace_listing_events', false, ['ENGINE' => 'InnoDB']);

        // Categoria interna → categoria do marketplace, sugerida nas próximas publicações.
        $this->forge->addField([
            'id'                     => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'marketplace_id'         => ['type' => 'INT', 'unsigned' => true],
            'category_id'            => ['type' => 'INT', 'unsigned' => true],
            'external_category_id'   => ['type' => 'VARCHAR', 'constraint' => 20],
            'external_category_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'             => ['type' => 'DATETIME', 'null' => true],
            'updated_at'             => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['marketplace_id', 'category_id']);
        $this->forge->addForeignKey('marketplace_id', 'marketplaces', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('category_id', 'categories', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('marketplace_category_mappings', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('marketplace_category_mappings', true);
        $this->forge->dropTable('marketplace_listing_events', true);
        $this->forge->dropTable('marketplace_listings', true);
    }
}

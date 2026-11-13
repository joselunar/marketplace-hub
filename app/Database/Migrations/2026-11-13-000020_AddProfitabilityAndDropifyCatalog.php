<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Módulo de rentabilidade pré-publicação e campos extras do catálogo Dropify.
 * Somente aditivo: não remove nem altera dados existentes.
 */
class AddProfitabilityAndDropifyCatalog extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('suppliers', [
            'provider_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
                'after'      => 'integration_type',
                'comment'    => 'Identificador do provedor (ex.: dropify). Null = genérico.',
            ],
        ]);
        $this->db->query('ALTER TABLE suppliers ADD INDEX suppliers_provider_code (provider_code)');

        $this->forge->addColumn('products', [
            'ncm'             => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'ean'],
            'cest'            => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'ncm'],
            'fiscal_origin'   => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true, 'after' => 'cest'],
            'origin_uf'       => ['type' => 'CHAR', 'constraint' => 2, 'null' => true, 'after' => 'fiscal_origin'],
            'source_provider' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'origin_uf'],
        ]);
        $this->db->query('ALTER TABLE products ADD INDEX products_source_provider (source_provider)');
        $this->db->query('ALTER TABLE products ADD INDEX products_origin_uf (origin_uf)');

        $this->forge->addColumn('supplier_offers', [
            'suggested_price'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true, 'after' => 'cost_price'],
            'weight_grams'         => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'shipping_days'],
            'height_cm'            => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true, 'after' => 'weight_grams'],
            'width_cm'             => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true, 'after' => 'height_cm'],
            'length_cm'            => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true, 'after' => 'width_cm'],
            'ncm'                  => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'length_cm'],
            'cest'                 => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'ncm'],
            'fiscal_origin'        => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true, 'after' => 'cest'],
            'origin_uf'            => ['type' => 'CHAR', 'constraint' => 2, 'null' => true, 'after' => 'fiscal_origin'],
            'origin_postal_code'    => ['type' => 'VARCHAR', 'constraint' => 12, 'null' => true, 'after' => 'origin_uf'],
            'source_category'      => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'after' => 'origin_postal_code'],
            'availability_status'  => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true, 'after' => 'source_category'],
            'short_description'    => ['type' => 'TEXT', 'null' => true, 'after' => 'availability_status'],
            'image_urls'           => ['type' => 'JSON', 'null' => true, 'after' => 'short_description'],
            'metadata'             => ['type' => 'JSON', 'null' => true, 'after' => 'image_urls'],
        ]);

        $this->forge->addField([
            'id'                         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'product_id'                 => ['type' => 'INT', 'unsigned' => true],
            'supplier_id'                => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'supplier_offer_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'classification'             => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'aguardando_dados'],
            'sale_price'                 => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'cost_price'                 => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'marketplace_fee_total'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'marketplace_fee_source'     => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'desconhecido'],
            'seller_shipping_cost'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'seller_shipping_source'     => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'desconhecido'],
            'variable_costs_total'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'contribution_margin'        => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'contribution_margin_pct'    => ['type' => 'DECIMAL', 'constraint' => '9,2', 'null' => true],
            'operating_result'           => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'minimum_viable_price'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'break_even_units'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'classic_margin_pct'         => ['type' => 'DECIMAL', 'constraint' => '9,2', 'null' => true],
            'premium_margin_pct'         => ['type' => 'DECIMAL', 'constraint' => '9,2', 'null' => true],
            'classic_fee_total'          => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'premium_fee_total'          => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'preferred_listing_type'     => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'cost_reliability'           => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'desconhecido'],
            'unknown_costs'              => ['type' => 'JSON', 'null' => true],
            'breakdown'                  => ['type' => 'JSON', 'null' => true],
            'block_reasons'              => ['type' => 'JSON', 'null' => true],
            'marketplace_category_id'    => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'quote_account_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'quoted_at'                  => ['type' => 'DATETIME', 'null' => true],
            'calculation_source'         => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'engine'],
            'created_at'                 => ['type' => 'DATETIME', 'null' => true],
            'updated_at'                 => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('product_id');
        $this->forge->addKey('classification');
        $this->forge->addKey('contribution_margin_pct');
        $this->forge->addKey('supplier_id');
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('supplier_id', 'suppliers', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('supplier_offer_id', 'supplier_offers', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('quote_account_id', 'marketplace_accounts', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('profitability_analyses', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'product_id'      => ['type' => 'INT', 'unsigned' => true],
            'analysis_id'     => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'classification'  => ['type' => 'VARCHAR', 'constraint' => 30],
            'sale_price'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'contribution_margin_pct' => ['type' => 'DECIMAL', 'constraint' => '9,2', 'null' => true],
            'cost_reliability'=> ['type' => 'VARCHAR', 'constraint' => 20],
            'calculation_source' => ['type' => 'VARCHAR', 'constraint' => 40],
            'snapshot'        => ['type' => 'JSON'],
            'created_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['product_id', 'created_at']);
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('analysis_id', 'profitability_analyses', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('profitability_analysis_history', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'supplier_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'provider_code' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'dropify'],
            'item_code'     => ['type' => 'VARCHAR', 'constraint' => 80],
            'label'         => ['type' => 'VARCHAR', 'constraint' => 255],
            'status'        => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pendente'],
            'evidence_note' => ['type' => 'TEXT', 'null' => true],
            'sort_order'    => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'updated_by'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['provider_code', 'item_code']);
        $this->forge->addKey('status');
        $this->forge->addForeignKey('supplier_id', 'suppliers', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('updated_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('homologation_checklist_items', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('homologation_checklist_items', true);
        $this->forge->dropTable('profitability_analysis_history', true);
        $this->forge->dropTable('profitability_analyses', true);

        $this->forge->dropColumn('supplier_offers', [
            'suggested_price', 'weight_grams', 'height_cm', 'width_cm', 'length_cm',
            'ncm', 'cest', 'fiscal_origin', 'origin_uf', 'origin_postal_code',
            'source_category', 'availability_status', 'short_description', 'image_urls', 'metadata',
        ]);

        $this->db->query('ALTER TABLE products DROP INDEX products_source_provider');
        $this->db->query('ALTER TABLE products DROP INDEX products_origin_uf');
        $this->forge->dropColumn('products', ['ncm', 'cest', 'fiscal_origin', 'origin_uf', 'source_provider']);

        $this->db->query('ALTER TABLE suppliers DROP INDEX suppliers_provider_code');
        $this->forge->dropColumn('suppliers', ['provider_code']);
    }
}

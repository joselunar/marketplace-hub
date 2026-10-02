<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOrderTables extends Migration
{
    public function up(): void
    {
        // Notificações recebidas dos marketplaces. O webhook só grava; o
        // processamento (consulta do recurso na API) acontece depois.
        $this->forge->addField([
            'id'                     => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'provider'               => ['type' => 'VARCHAR', 'constraint' => 30],
            'marketplace_account_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'notification_id'        => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'dedupe_key'             => ['type' => 'CHAR', 'constraint' => 40],
            'topic'                  => ['type' => 'VARCHAR', 'constraint' => 60],
            'resource'               => ['type' => 'VARCHAR', 'constraint' => 255],
            'user_id'                => ['type' => 'VARCHAR', 'constraint' => 30],
            'application_id'         => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'attempts'               => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'status'                 => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
            'process_attempts'       => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'message'                => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'payload'                => ['type' => 'TEXT'],
            'sent_at'                => ['type' => 'DATETIME', 'null' => true],
            'received_at'            => ['type' => 'DATETIME'],
            'locked_at'              => ['type' => 'DATETIME', 'null' => true],
            'processed_at'           => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('dedupe_key');
        $this->forge->addKey(['status', 'id']);
        $this->forge->addKey(['marketplace_account_id', 'topic', 'resource']);
        $this->forge->addKey('received_at');
        $this->forge->addForeignKey('marketplace_account_id', 'marketplace_accounts', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('webhook_events', false, ['ENGINE' => 'InnoDB']);

        // Pedidos do marketplace. Valores em DECIMAL; o comprador fica só pelo ID externo.
        $this->forge->addField([
            'id'                     => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'marketplace_id'         => ['type' => 'INT', 'unsigned' => true],
            'marketplace_account_id' => ['type' => 'INT', 'unsigned' => true],
            'external_order_id'      => ['type' => 'VARCHAR', 'constraint' => 30],
            'pack_id'                => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'shipping_id'            => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'buyer_external_id'      => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'status'                 => ['type' => 'VARCHAR', 'constraint' => 30],
            'status_detail'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'currency_id'            => ['type' => 'VARCHAR', 'constraint' => 5],
            'total_amount'           => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'paid_amount'            => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'marketplace_fees'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'fees_estimated'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'shipping_amount'        => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'supplier_cost'          => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'estimated_profit'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'fulfillment_status'     => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'awaiting_payment'],
            'issue'                  => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'fraud_risk'             => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'tags'                   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'ordered_at'             => ['type' => 'DATETIME'],
            'closed_at'              => ['type' => 'DATETIME', 'null' => true],
            'external_updated_at'    => ['type' => 'DATETIME', 'null' => true],
            'last_sync_at'           => ['type' => 'DATETIME', 'null' => true],
            'created_at'             => ['type' => 'DATETIME', 'null' => true],
            'updated_at'             => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['marketplace_account_id', 'external_order_id']);
        $this->forge->addKey('external_order_id');
        $this->forge->addKey('status');
        $this->forge->addKey('fulfillment_status');
        $this->forge->addKey('ordered_at');
        $this->forge->addKey('created_at');
        $this->forge->addForeignKey('marketplace_id', 'marketplaces', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('marketplace_account_id', 'marketplace_accounts', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('orders', false, ['ENGINE' => 'InnoDB']);

        // Itens do pedido com o fornecedor reservado e o custo do momento da reserva.
        $this->forge->addField([
            'id'                     => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'order_id'               => ['type' => 'INT', 'unsigned' => true],
            'product_id'             => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'marketplace_listing_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'supplier_offer_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'supplier_id'            => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'external_item_id'       => ['type' => 'VARCHAR', 'constraint' => 30],
            // Vazio (e não NULL) sem variação, para a chave única valer.
            'variation_id'           => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => ''],
            'title'                  => ['type' => 'VARCHAR', 'constraint' => 255],
            'seller_sku'             => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'quantity'               => ['type' => 'INT', 'unsigned' => true],
            'unit_price'             => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'sale_fee'               => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'supplier_cost'          => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'supplier_shipping'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'estimated_profit'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'allocation_note'        => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at'             => ['type' => 'DATETIME', 'null' => true],
            'updated_at'             => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['order_id', 'external_item_id', 'variation_id']);
        $this->forge->addKey('product_id');
        $this->forge->addForeignKey('order_id', 'orders', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('marketplace_listing_id', 'marketplace_listings', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('supplier_offer_id', 'supplier_offers', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('supplier_id', 'suppliers', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('order_items', false, ['ENGINE' => 'InnoDB']);

        // Pedido de compra ao fornecedor (um por fornecedor envolvido no pedido).
        $this->forge->addField([
            'id'                         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'order_id'                   => ['type' => 'INT', 'unsigned' => true],
            'supplier_id'                => ['type' => 'INT', 'unsigned' => true],
            'external_supplier_order_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'status'                     => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
            'cost'                       => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'tracking_code'              => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'notes'                      => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'sent_by'                    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'sent_at'                    => ['type' => 'DATETIME', 'null' => true],
            'created_at'                 => ['type' => 'DATETIME', 'null' => true],
            'updated_at'                 => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['order_id', 'supplier_id']);
        $this->forge->addKey('status');
        $this->forge->addForeignKey('order_id', 'orders', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('supplier_id', 'suppliers', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('sent_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('supplier_orders', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('supplier_orders', true);
        $this->forge->dropTable('order_items', true);
        $this->forge->dropTable('orders', true);
        $this->forge->dropTable('webhook_events', true);
    }
}

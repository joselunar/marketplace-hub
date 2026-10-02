<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMarketplaceTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 100],
            'slug'       => ['type' => 'VARCHAR', 'constraint' => 50],
            'active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('marketplaces', false, ['ENGINE' => 'InnoDB']);

        // Tokens ficam cifrados (AES-256 + HMAC via encryption.key) e nunca em texto puro.
        $this->forge->addField([
            'id'                      => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'marketplace_id'          => ['type' => 'INT', 'unsigned' => true],
            'name'                    => ['type' => 'VARCHAR', 'constraint' => 150],
            'external_user_id'        => ['type' => 'VARCHAR', 'constraint' => 50],
            'site_id'                 => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'access_token_encrypted'  => ['type' => 'TEXT', 'null' => true],
            'refresh_token_encrypted' => ['type' => 'TEXT', 'null' => true],
            'token_expires_at'        => ['type' => 'DATETIME', 'null' => true],
            'scope'                   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status'                  => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'connected'],
            'active'                  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'last_error'              => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'token_refreshed_at'      => ['type' => 'DATETIME', 'null' => true],
            'connected_by'            => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'connected_at'            => ['type' => 'DATETIME', 'null' => true],
            'created_at'              => ['type' => 'DATETIME', 'null' => true],
            'updated_at'              => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['marketplace_id', 'external_user_id']);
        $this->forge->addKey(['status', 'token_expires_at']);
        $this->forge->addForeignKey('marketplace_id', 'marketplaces', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('connected_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('marketplace_accounts', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('marketplace_accounts', true);
        $this->forge->dropTable('marketplaces', true);
    }
}

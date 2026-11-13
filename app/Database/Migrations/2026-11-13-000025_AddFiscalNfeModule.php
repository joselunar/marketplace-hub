<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Módulo fiscal NF-e (emissor próprio via NFePHP / SEFAZ).
 * Somente aditivo. Emissão real permanece desligada por configuração.
 */
class AddFiscalNfeModule extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'legal_name'            => ['type' => 'VARCHAR', 'constraint' => 190],
            'trade_name'            => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'document'              => ['type' => 'VARCHAR', 'constraint' => 20],
            'state_registration'    => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'municipal_registration'=> ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'crt'                   => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'tax_regime_note'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'zip_code'              => ['type' => 'VARCHAR', 'constraint' => 12, 'null' => true],
            'street'                => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'number'                => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'complement'            => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'district'              => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'city'                  => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'city_ibge'             => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'uf'                    => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            'phone'                 => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'email'                 => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'serie_nfe'             => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 1],
            'next_number_nfe'       => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'tax_params_json'       => ['type' => 'JSON', 'null' => true],
            'tax_params_validated'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'tax_params_validated_at'=> ['type' => 'DATETIME', 'null' => true],
            'tax_params_validated_by'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'            => ['type' => 'DATETIME', 'null' => true],
            'updated_at'            => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('document');
        $this->forge->addForeignKey('tax_params_validated_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('fiscal_companies', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'fiscal_company_id'  => ['type' => 'INT', 'unsigned' => true],
            'label'              => ['type' => 'VARCHAR', 'constraint' => 120, 'default' => 'A1'],
            'storage_path'       => ['type' => 'VARCHAR', 'constraint' => 500],
            'password_encrypted' => ['type' => 'TEXT'],
            'subject'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'serial_number'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'valid_from'         => ['type' => 'DATETIME', 'null' => true],
            'valid_to'           => ['type' => 'DATETIME', 'null' => true],
            'active'             => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_by'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
            'updated_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['fiscal_company_id', 'active']);
        $this->forge->addForeignKey('fiscal_company_id', 'fiscal_companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('fiscal_certificates', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id'                    => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'fiscal_company_id'     => ['type' => 'INT', 'unsigned' => true],
            'order_id'              => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'marketplace_account_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'external_order_id'     => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'shipping_id'           => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'pack_id'               => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'model'                 => ['type' => 'CHAR', 'constraint' => 2, 'default' => '55'],
            'serie'                 => ['type' => 'SMALLINT', 'unsigned' => true],
            'number'                => ['type' => 'INT', 'unsigned' => true],
            'access_key'            => ['type' => 'VARCHAR', 'constraint' => 44, 'null' => true],
            'environment'           => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'homologacao'],
            'status'                => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'rascunho'],
            'sefaz_status_code'     => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'sefaz_message'         => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'protocol'              => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'receipt'               => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'issued_at'             => ['type' => 'DATETIME', 'null' => true],
            'authorized_at'         => ['type' => 'DATETIME', 'null' => true],
            'cancelled_at'          => ['type' => 'DATETIME', 'null' => true],
            'xml_signed_path'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'xml_authorized_path'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'danfe_path'            => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'payload_json'          => ['type' => 'JSON', 'null' => true],
            'dedupe_key'            => ['type' => 'CHAR', 'constraint' => 64],
            'ml_send_status'        => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'ml_send_message'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'ml_sent_at'            => ['type' => 'DATETIME', 'null' => true],
            'created_by'            => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'            => ['type' => 'DATETIME', 'null' => true],
            'updated_at'            => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('dedupe_key');
        $this->forge->addUniqueKey(['fiscal_company_id', 'model', 'serie', 'number', 'environment']);
        $this->forge->addKey('access_key');
        $this->forge->addKey(['status', 'issued_at']);
        $this->forge->addKey('order_id');
        $this->forge->addKey('external_order_id');
        $this->forge->addForeignKey('fiscal_company_id', 'fiscal_companies', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('order_id', 'orders', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('marketplace_account_id', 'marketplace_accounts', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('fiscal_invoices', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id'                => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'fiscal_invoice_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'event_type'        => ['type' => 'VARCHAR', 'constraint' => 40],
            'message'           => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'payload_json'      => ['type' => 'JSON', 'null' => true],
            'created_by'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['fiscal_invoice_id', 'id']);
        $this->forge->addForeignKey('fiscal_invoice_id', 'fiscal_invoices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('fiscal_invoice_events', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('fiscal_invoice_events', true);
        $this->forge->dropTable('fiscal_invoices', true);
        $this->forge->dropTable('fiscal_certificates', true);
        $this->forge->dropTable('fiscal_companies', true);
    }
}

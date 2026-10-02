<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Histórico de trocas do fornecedor principal de cada produto.
 *
 * Os nomes dos fornecedores são copiados para o registro continuar legível
 * mesmo depois que a oferta for excluída; por isso os IDs de oferta não têm FK.
 */
class CreateSupplierSelectionLogsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                     => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'product_id'             => ['type' => 'INT', 'unsigned' => true],
            'previous_offer_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'previous_supplier_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'previous_score'         => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'new_offer_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'new_supplier_name'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'new_score'              => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'reason'                 => ['type' => 'VARCHAR', 'constraint' => 500],
            'trigger'                => ['type' => 'VARCHAR', 'constraint' => 40],
            'user_id'                => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'ranking'                => ['type' => 'JSON', 'null' => true],
            'created_at'             => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['product_id', 'created_at']);
        $this->forge->addKey('created_at');
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('supplier_selection_logs', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('supplier_selection_logs', true);
    }
}

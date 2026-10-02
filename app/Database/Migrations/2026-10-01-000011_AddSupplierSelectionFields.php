<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Campos usados pelo motor de seleção de fornecedores.
 *
 * - safety_stock em fornecedor e produto: null = herda do nível acima
 *   (produto > fornecedor > configuração global).
 * - blocked/blocked_reason: bloqueio manual de uma oferta.
 * - selection_* e evaluated_at: resultado da última avaliação da oferta.
 * - products.selected_offer_id: fornecedor principal escolhido.
 */
class AddSupplierSelectionFields extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('suppliers', [
            'safety_stock' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true, 'after' => 'default_shipping_days'],
        ]);

        $this->forge->addColumn('products', [
            'safety_stock'          => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true, 'after' => 'length'],
            'selected_offer_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'safety_stock'],
            'selection_updated_at'  => ['type' => 'DATETIME', 'null' => true, 'after' => 'selected_offer_id'],
        ]);

        $this->forge->addColumn('supplier_offers', [
            'blocked'             => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 0, 'after' => 'active'],
            'blocked_reason'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'blocked'],
            'available_stock'     => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'blocked_reason'],
            'selection_score'     => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true, 'after' => 'available_stock'],
            'selection_rank'      => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true, 'after' => 'selection_score'],
            'ineligibility_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'after' => 'selection_rank'],
            'evaluated_at'        => ['type' => 'DATETIME', 'null' => true, 'after' => 'ineligibility_reason'],
        ]);

        $this->db->query('ALTER TABLE products ADD CONSTRAINT products_selected_offer_id_foreign '
            . 'FOREIGN KEY (selected_offer_id) REFERENCES supplier_offers (id) ON DELETE SET NULL ON UPDATE CASCADE');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE products DROP FOREIGN KEY products_selected_offer_id_foreign');
        $this->db->query('ALTER TABLE products DROP INDEX products_selected_offer_id_foreign');

        $this->forge->dropColumn('supplier_offers', [
            'blocked', 'blocked_reason', 'available_stock', 'selection_score', 'selection_rank', 'ineligibility_reason', 'evaluated_at',
        ]);
        $this->forge->dropColumn('products', ['safety_stock', 'selected_offer_id', 'selection_updated_at']);
        $this->forge->dropColumn('suppliers', ['safety_stock']);
    }
}

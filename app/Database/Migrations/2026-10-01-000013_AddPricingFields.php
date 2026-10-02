<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Campos de precificação.
 *
 * - Margens em categoria e produto: null = herda do nível acima
 *   (produto > categoria > categoria pai > configuração global).
 * - products.sale_price: preço fixado manualmente (null = usa o sugerido).
 * - products.extra_cost: custo extra por unidade deste produto (embalagem especial, brinde...).
 * - suggested_price, minimum_price, estimated_*, price_status: resultado do
 *   último cálculo com o fornecedor principal, para listagens e alertas.
 */
class AddPricingFields extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('categories', [
            'desired_margin_percentage' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true, 'after' => 'description'],
            'minimum_margin_percentage' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true, 'after' => 'desired_margin_percentage'],
        ]);

        $this->forge->addColumn('products', [
            'desired_margin_percentage' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true, 'after' => 'selection_updated_at'],
            'minimum_margin_percentage' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true, 'after' => 'desired_margin_percentage'],
            'extra_cost'                => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true, 'after' => 'minimum_margin_percentage'],
            'sale_price'                => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true, 'after' => 'extra_cost'],
            'suggested_price'           => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true, 'after' => 'sale_price'],
            'minimum_price'             => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true, 'after' => 'suggested_price'],
            'estimated_profit'          => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true, 'after' => 'minimum_price'],
            'estimated_margin'          => ['type' => 'DECIMAL', 'constraint' => '9,2', 'null' => true, 'after' => 'estimated_profit'],
            'price_status'              => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'estimated_margin'],
            'pricing_updated_at'        => ['type' => 'DATETIME', 'null' => true, 'after' => 'price_status'],
        ]);

        $this->db->query('ALTER TABLE products ADD INDEX products_price_status (price_status)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE products DROP INDEX products_price_status');

        $this->forge->dropColumn('products', [
            'desired_margin_percentage', 'minimum_margin_percentage', 'extra_cost', 'sale_price', 'suggested_price',
            'minimum_price', 'estimated_profit', 'estimated_margin', 'price_status', 'pricing_updated_at',
        ]);
        $this->forge->dropColumn('categories', ['desired_margin_percentage', 'minimum_margin_percentage']);
    }
}

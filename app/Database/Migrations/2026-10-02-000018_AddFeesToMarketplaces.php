<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tarifas padrão por marketplace. NULL usa o valor global das configurações
 * de precificação.
 */
class AddFeesToMarketplaces extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('marketplaces', [
            'fee_percentage'      => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true, 'after' => 'active'],
            'fixed_fee'           => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true, 'after' => 'fee_percentage'],
            'fixed_fee_threshold' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true, 'after' => 'fixed_fee'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('marketplaces', ['fee_percentage', 'fixed_fee', 'fixed_fee_threshold']);
    }
}

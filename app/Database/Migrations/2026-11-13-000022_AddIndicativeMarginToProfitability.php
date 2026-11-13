<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Margem indicativa (confirmados + estimados) para ranking pré-contratação.
 * Somente aditivo.
 */
class AddIndicativeMarginToProfitability extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('profitability_analyses', [
            'indicative_margin' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'null'       => true,
                'after'      => 'contribution_margin_pct',
            ],
            'indicative_margin_pct' => [
                'type'       => 'DECIMAL',
                'constraint' => '9,2',
                'null'       => true,
                'after'      => 'indicative_margin',
            ],
        ]);
        $this->db->query('ALTER TABLE profitability_analyses ADD INDEX profitability_indicative_pct (indicative_margin_pct)');
    }

    public function down(): void
    {
        $this->forge->dropColumn('profitability_analyses', ['indicative_margin', 'indicative_margin_pct']);
    }
}

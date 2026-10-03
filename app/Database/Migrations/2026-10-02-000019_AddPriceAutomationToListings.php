<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Estado da automação de preços do Mercado Livre em cada anúncio.
 * Status NULL quer dizer que o anúncio não tem automação.
 */
class AddPriceAutomationToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('marketplace_listings', [
            'price_automation_status'    => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'listing_fee_amount'],
            'price_automation_rule'      => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'price_automation_status'],
            'price_automation_min'       => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true, 'after' => 'price_automation_rule'],
            'price_automation_max'       => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true, 'after' => 'price_automation_min'],
            'price_automation_cause'     => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'price_automation_max'],
            'price_automation_synced_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'price_automation_cause'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('marketplace_listings', [
            'price_automation_status', 'price_automation_rule', 'price_automation_min',
            'price_automation_max', 'price_automation_cause', 'price_automation_synced_at',
        ]);
    }
}

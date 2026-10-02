<?php

namespace App\Database\Seeds;

use App\Services\Settings\SettingsService;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;
use RuntimeException;

/**
 * Popula o banco com dados de demonstração.
 * Uso: php spark db:seed DevelopmentSeeder
 */
class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (ENVIRONMENT === 'production') {
            throw new RuntimeException('DevelopmentSeeder não pode ser executado em produção.');
        }

        $this->call(SettingsSeeder::class);
        $this->demoPricingSettings();
        $this->call(UserSeeder::class);
        $this->call(CategorySeeder::class);
        $this->call(SupplierSeeder::class);
        $this->call(CatalogSeeder::class);

        if (is_cli() && ENVIRONMENT !== 'testing') {
            CLI::write('Dados de demonstração criados.', 'green');
        }
    }

    /**
     * Valores fictícios de tarifa para a demonstração mostrar todos os
     * componentes do preço. Em uso real, preencha com a tabela oficial do
     * marketplace. Só altera se as tarifas ainda estiverem zeradas.
     */
    private function demoPricingSettings(): void
    {
        $settings = new SettingsService();

        if (bccomp($settings->getDecimal('pricing.marketplace_fee_percentage'), '0', 2) !== 0) {
            return;
        }

        $values = array_map(static fn ($value) => is_bool($value) ? ($value ? '1' : '0') : (string) $value, $settings->all());

        $settings->updateMany([
            'pricing.marketplace_fee_percentage'      => '14.00',
            'pricing.marketplace_fixed_fee'           => '6.00',
            'pricing.marketplace_fixed_fee_threshold' => '79.00',
            'pricing.extra_fixed_cost'                => '1.50',
        ] + $values);
    }
}

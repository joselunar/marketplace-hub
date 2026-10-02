<?php

namespace App\Database\Seeds;

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
        $this->call(UserSeeder::class);
        $this->call(CategorySeeder::class);
        $this->call(SupplierSeeder::class);
        $this->call(CatalogSeeder::class);

        if (is_cli() && ENVIRONMENT !== 'testing') {
            CLI::write('Dados de demonstração criados.', 'green');
        }
    }
}

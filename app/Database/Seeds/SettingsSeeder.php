<?php

namespace App\Database\Seeds;

use App\Services\Settings\SettingsService;
use CodeIgniter\Database\Seeder;

/**
 * Cria as configurações ausentes com os valores padrão de Config\SystemSettings.
 * Pode rodar em produção: não sobrescreve valores existentes.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        (new SettingsService())->seedDefaults();
    }
}

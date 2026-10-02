<?php

namespace App\Database\Seeds;

use App\Models\SupplierModel;
use CodeIgniter\Database\Seeder;

/**
 * Fornecedores fictícios para demonstração do ranking.
 */
class SupplierSeeder extends Seeder
{
    public const SUPPLIERS = [
        [
            'name'                  => 'Distribuidora Alpha',
            'legal_name'            => 'Distribuidora Alpha Comércio Ltda (fictícia)',
            'document'              => '11111111000191',
            'email'                 => 'comercial@alpha.example',
            'phone'                 => '(11) 4000-0001',
            'website'               => 'https://alpha.example',
            'integration_type'      => 'csv',
            'status'                => 'active',
            'priority'              => 7,
            'default_shipping_days' => 2,
            'notes'                 => 'Envia planilha CSV diária com custo e estoque.',
        ],
        [
            'name'                  => 'Fornecedor Beta',
            'legal_name'            => 'Beta Importados ME (fictícia)',
            'document'              => '22222222000192',
            'email'                 => 'vendas@beta.example',
            'phone'                 => '(21) 4000-0002',
            'website'               => null,
            'integration_type'      => 'manual',
            'status'                => 'active',
            'priority'              => 5,
            'default_shipping_days' => 1,
            'safety_stock'          => 3,
            'notes'                 => 'Preços mais baixos, porém estoque reduzido. Reserva de segurança maior que a global.',
        ],
        [
            'name'                  => 'Fornecedor Gamma',
            'legal_name'            => 'Gamma Atacado S.A. (fictícia)',
            'document'              => '33333333000193',
            'email'                 => 'atendimento@gamma.example',
            'phone'                 => '(31) 4000-0003',
            'website'               => 'https://gamma.example',
            'integration_type'      => 'manual',
            'status'                => 'active',
            'priority'              => 6,
            'default_shipping_days' => 3,
            'notes'                 => 'Grande volume de estoque.',
        ],
    ];

    public function run(): void
    {
        $model = new SupplierModel();

        foreach (self::SUPPLIERS as $supplier) {
            if ($model->where('document', $supplier['document'])->countAllResults() > 0) {
                continue;
            }
            $model->skipValidation(true)->insert($supplier);
        }
    }
}

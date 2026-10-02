<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Catálogo das configurações editáveis pelo painel.
 *
 * Os valores reais ficam na tabela `settings`; o "default" aqui é usado
 * apenas pelo seeder e como fallback quando a chave ainda não existe no banco.
 */
class SystemSettings extends BaseConfig
{
    /**
     * @var array<string, string>
     */
    public array $groups = [
        'pricing'   => 'Precificação',
        'stock'     => 'Estoque',
        'suppliers' => 'Fornecedores',
        'ranking'   => 'Pesos do ranking de fornecedores',
        'sync'      => 'Sincronização',
    ];

    /**
     * @var array<string, array{group: string, type: string, label: string, default: mixed, rules: string, help?: string}>
     */
    public array $definitions = [
        'pricing.desired_margin_percentage' => [
            'group'   => 'pricing',
            'type'    => 'decimal',
            'label'   => 'Margem desejada (%)',
            'default' => '25.00',
            'rules'   => 'required|decimal|greater_than_equal_to[0]|less_than[100]',
        ],
        'pricing.minimum_margin_percentage' => [
            'group'   => 'pricing',
            'type'    => 'decimal',
            'label'   => 'Margem mínima (%)',
            'default' => '10.00',
            'rules'   => 'required|decimal|greater_than_equal_to[0]|less_than[100]',
        ],
        'pricing.estimated_tax_percentage' => [
            'group'   => 'pricing',
            'type'    => 'decimal',
            'label'   => 'Imposto estimado (%)',
            'default' => '6.00',
            'rules'   => 'required|decimal|greater_than_equal_to[0]|less_than[100]',
        ],
        'stock.safety_quantity' => [
            'group'   => 'stock',
            'type'    => 'int',
            'label'   => 'Estoque de segurança global (unidades)',
            'default' => 2,
            'rules'   => 'required|is_natural',
            'help'    => 'Quantidade reservada que nunca é publicada nos marketplaces.',
        ],
        'suppliers.max_shipping_days' => [
            'group'   => 'suppliers',
            'type'    => 'int',
            'label'   => 'Prazo máximo do fornecedor (dias)',
            'default' => 7,
            'rules'   => 'required|is_natural_no_zero',
            'help'    => 'Ofertas com prazo de postagem acima deste limite ficam inelegíveis.',
        ],
        'suppliers.reliability_window_days' => [
            'group'   => 'suppliers',
            'type'    => 'int',
            'label'   => 'Janela de confiabilidade (dias)',
            'default' => 30,
            'rules'   => 'required|is_natural_no_zero|less_than_equal_to[365]',
            'help'    => 'Período de sincronizações considerado no cálculo de confiabilidade do fornecedor.',
        ],
        'ranking.weight_price' => [
            'group'   => 'ranking',
            'type'    => 'decimal',
            'label'   => 'Peso: preço final',
            'default' => '35',
            'rules'   => 'required|decimal|greater_than_equal_to[0]|less_than_equal_to[100]',
        ],
        'ranking.weight_stock' => [
            'group'   => 'ranking',
            'type'    => 'decimal',
            'label'   => 'Peso: estoque disponível',
            'default' => '25',
            'rules'   => 'required|decimal|greater_than_equal_to[0]|less_than_equal_to[100]',
        ],
        'ranking.weight_shipping' => [
            'group'   => 'ranking',
            'type'    => 'decimal',
            'label'   => 'Peso: prazo de postagem',
            'default' => '20',
            'rules'   => 'required|decimal|greater_than_equal_to[0]|less_than_equal_to[100]',
        ],
        'ranking.weight_reliability' => [
            'group'   => 'ranking',
            'type'    => 'decimal',
            'label'   => 'Peso: confiabilidade',
            'default' => '15',
            'rules'   => 'required|decimal|greater_than_equal_to[0]|less_than_equal_to[100]',
        ],
        'ranking.weight_priority' => [
            'group'   => 'ranking',
            'type'    => 'decimal',
            'label'   => 'Peso: prioridade do fornecedor',
            'default' => '5',
            'rules'   => 'required|decimal|greater_than_equal_to[0]|less_than_equal_to[100]',
        ],
        'sync.auto_update_stock' => [
            'group'   => 'sync',
            'type'    => 'bool',
            'label'   => 'Atualização automática de estoque',
            'default' => false,
            'rules'   => 'permit_empty|in_list[0,1]',
        ],
        'sync.auto_update_price' => [
            'group'   => 'sync',
            'type'    => 'bool',
            'label'   => 'Atualização automática de preço',
            'default' => false,
            'rules'   => 'permit_empty|in_list[0,1]',
        ],
    ];
}

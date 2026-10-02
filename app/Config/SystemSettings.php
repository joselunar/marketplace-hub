<?php

namespace Config;

use App\DTO\Pricing\PricingConfig;
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
        'marketplace' => 'Marketplaces',
    ];

    /**
     * @var array<string, array{group: string, type: string, label: string, default: mixed, rules: string, help?: string, options?: array<string, string>}>
     */
    public array $definitions = [
        'pricing.desired_margin_percentage' => [
            'group'   => 'pricing',
            'type'    => 'decimal',
            'label'   => 'Margem desejada (%)',
            'default' => '25.00',
            'rules'   => 'required|decimal|greater_than_equal_to[0]|less_than[100]',
            'help'    => 'Lucro sobre o preço de venda. Categorias e produtos podem definir a sua.',
        ],
        'pricing.minimum_margin_percentage' => [
            'group'   => 'pricing',
            'type'    => 'decimal',
            'label'   => 'Margem mínima (%)',
            'default' => '10.00',
            'rules'   => 'required|decimal|greater_than_equal_to[0]|less_than[100]',
            'help'    => 'Preços abaixo dela são recusados e ofertas que não a atingem ficam inelegíveis.',
        ],
        'pricing.estimated_tax_percentage' => [
            'group'   => 'pricing',
            'type'    => 'decimal',
            'label'   => 'Imposto estimado (%)',
            'default' => '6.00',
            'rules'   => 'required|decimal|greater_than_equal_to[0]|less_than[100]',
            'help'    => 'Sobre o preço de venda (ex.: alíquota do Simples Nacional).',
        ],
        'pricing.marketplace_fee_percentage' => [
            'group'   => 'pricing',
            'type'    => 'decimal',
            'label'   => 'Comissão do marketplace (%)',
            'default' => '0.00',
            'rules'   => 'required|decimal|greater_than_equal_to[0]|less_than[100]',
            'help'    => 'Preencha com o valor da tabela oficial de tarifas do marketplace para o tipo de anúncio usado.',
        ],
        'pricing.marketplace_fixed_fee' => [
            'group'   => 'pricing',
            'type'    => 'decimal',
            'label'   => 'Tarifa fixa do marketplace por unidade (R$)',
            'default' => '0.00',
            'rules'   => 'required|decimal|greater_than_equal_to[0]|less_than[100000]',
        ],
        'pricing.marketplace_fixed_fee_threshold' => [
            'group'   => 'pricing',
            'type'    => 'decimal',
            'label'   => 'Tarifa fixa cobrada abaixo de (R$)',
            'default' => '0.00',
            'rules'   => 'required|decimal|greater_than_equal_to[0]|less_than[10000000]',
            'help'    => 'A tarifa fixa só se aplica a vendas com preço abaixo deste valor. Use 0 para cobrar sempre.',
        ],
        'pricing.extra_cost_percentage' => [
            'group'   => 'pricing',
            'type'    => 'decimal',
            'label'   => 'Custos extras sobre o preço (%)',
            'default' => '0.00',
            'rules'   => 'required|decimal|greater_than_equal_to[0]|less_than[100]',
            'help'    => 'Ex.: antecipação de recebíveis, reserva para devoluções.',
        ],
        'pricing.extra_fixed_cost' => [
            'group'   => 'pricing',
            'type'    => 'decimal',
            'label'   => 'Custos extras por unidade (R$)',
            'default' => '0.00',
            'rules'   => 'required|decimal|greater_than_equal_to[0]|less_than[100000]',
            'help'    => 'Ex.: embalagem e etiqueta. Produtos podem somar um custo extra próprio.',
        ],
        'pricing.rounding' => [
            'group'   => 'pricing',
            'type'    => 'string',
            'label'   => 'Arredondamento do preço sugerido',
            'default' => 'ending_90',
            'rules'   => 'required|in_list[cents,ending_90,ending_99,integer]',
            'options' => PricingConfig::ROUNDING_OPTIONS,
            'help'    => 'Sempre para cima, para não reduzir a margem.',
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
            'help'    => 'Envia aos anúncios o estoque seguro do fornecedor principal sempre que ele mudar e na conferência periódica.',
        ],
        'sync.auto_update_price' => [
            'group'   => 'sync',
            'type'    => 'bool',
            'label'   => 'Atualização automática de preço',
            'default' => false,
            'rules'   => 'permit_empty|in_list[0,1]',
            'help'    => 'Recalcula o preço com a tarifa oficial quando custo ou fornecedor mudam. Preços abaixo da margem mínima nunca são enviados.',
        ],
        'sync.supplier_stock_interval' => [
            'group'   => 'sync',
            'type'    => 'int',
            'label'   => 'Estoque dos fornecedores: a cada (min)',
            'default' => 60,
            'rules'   => 'required|is_natural|less_than_equal_to[10080]',
            'help'    => 'Só fornecedores com adapter de integração. Use 0 para desligar.',
        ],
        'sync.supplier_price_interval' => [
            'group'   => 'sync',
            'type'    => 'int',
            'label'   => 'Custos dos fornecedores: a cada (min)',
            'default' => 360,
            'rules'   => 'required|is_natural|less_than_equal_to[10080]',
            'help'    => 'Só fornecedores com adapter de integração. Use 0 para desligar.',
        ],
        'sync.marketplace_stock_interval' => [
            'group'   => 'sync',
            'type'    => 'int',
            'label'   => 'Conferência do estoque dos anúncios: a cada (min)',
            'default' => 30,
            'rules'   => 'required|is_natural|less_than_equal_to[10080]',
            'help'    => 'Executada só com a atualização automática de estoque ligada. Use 0 para desligar.',
        ],
        'sync.marketplace_price_interval' => [
            'group'   => 'sync',
            'type'    => 'int',
            'label'   => 'Conferência do preço dos anúncios: a cada (min)',
            'default' => 360,
            'rules'   => 'required|is_natural|less_than_equal_to[10080]',
            'help'    => 'Executada só com a atualização automática de preço ligada. Consulta a tarifa oficial de cada anúncio. Use 0 para desligar.',
        ],
        'sync.orders_import_interval' => [
            'group'   => 'sync',
            'type'    => 'int',
            'label'   => 'Importação de pedidos (rede de segurança): a cada (min)',
            'default' => 360,
            'rules'   => 'required|is_natural|less_than_equal_to[10080]',
            'help'    => 'Busca os pedidos dos últimos 2 dias, caso alguma notificação tenha se perdido. Use 0 para desligar.',
        ],
        'marketplace.automatic_publish' => [
            'group'   => 'marketplace',
            'type'    => 'bool',
            'label'   => 'Publicação automática de anúncios (modo futuro)',
            'default' => false,
            'rules'   => 'permit_empty|in_list[0,1]',
            'help'    => 'Reservado para uma próxima fase. Enquanto isso, toda publicação passa pela prévia e precisa ser confirmada.',
        ],
    ];
}

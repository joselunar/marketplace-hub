<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Estrutura do menu lateral.
 *
 * - "prefix" define quando o item fica ativo (início da URL).
 * - "roles" restringe a visibilidade; vazio = todos os usuários autenticados.
 * - "soon" marca módulos de fases futuras (exibidos desabilitados).
 */
class Navigation extends BaseConfig
{
    /**
     * @var list<array{label: string, items: list<array{label: string, icon: string, url?: string, prefix?: string, roles?: list<string>, soon?: string}>}>
     */
    public array $sections = [
        [
            'label' => '',
            'items' => [
                ['label' => 'Dashboard', 'icon' => 'speedometer2', 'url' => 'dashboard', 'prefix' => 'dashboard'],
            ],
        ],
        [
            'label' => 'Catálogo',
            'items' => [
                ['label' => 'Produtos', 'icon' => 'box-seam', 'url' => 'products', 'prefix' => 'products'],
                ['label' => 'Categorias', 'icon' => 'diagram-3', 'url' => 'categories', 'prefix' => 'categories'],
                ['label' => 'Ofertas de fornecedores', 'icon' => 'tags', 'url' => 'offers', 'prefix' => 'offers'],
                ['label' => 'Simulador de preços', 'icon' => 'calculator', 'url' => 'pricing', 'prefix' => 'pricing'],
            ],
        ],
        [
            'label' => 'Fornecedores',
            'items' => [
                ['label' => 'Fornecedores', 'icon' => 'truck', 'url' => 'suppliers', 'prefix' => 'suppliers'],
                ['label' => 'Importações', 'icon' => 'cloud-upload', 'url' => 'imports', 'prefix' => 'imports'],
            ],
        ],
        [
            'label' => 'Marketplaces',
            'items' => [
                ['label' => 'Mercado Livre', 'icon' => 'shop', 'soon' => 'Fase 5'],
                ['label' => 'Anúncios', 'icon' => 'megaphone', 'soon' => 'Fase 6'],
            ],
        ],
        [
            'label' => 'Vendas',
            'items' => [
                ['label' => 'Pedidos', 'icon' => 'cart-check', 'soon' => 'Fase 7'],
            ],
        ],
        [
            'label' => 'Automação',
            'items' => [
                ['label' => 'Sincronizações', 'icon' => 'arrow-repeat', 'soon' => 'Fase 8'],
            ],
        ],
        [
            'label' => 'Sistema',
            'items' => [
                ['label' => 'Logs', 'icon' => 'journal-text', 'url' => 'logs', 'prefix' => 'logs', 'roles' => ['admin']],
                ['label' => 'Configurações', 'icon' => 'gear', 'url' => 'settings', 'prefix' => 'settings', 'roles' => ['admin']],
                ['label' => 'Usuários', 'icon' => 'people', 'url' => 'users', 'prefix' => 'users', 'roles' => ['admin']],
            ],
        ],
    ];
}

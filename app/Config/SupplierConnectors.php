<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Registro de adapters específicos por fornecedor.
 *
 * Fornecedores com integração via API ou XML precisam de um adapter próprio,
 * escrito a partir da documentação oficial do fornecedor. Registre aqui:
 *
 *     public array $adapters = [
 *         12 => \App\Services\Suppliers\Connectors\Adapters\FornecedorXyzConnector::class,
 *     ];
 *
 * A chave é o ID do fornecedor (tabela suppliers).
 */
class SupplierConnectors extends BaseConfig
{
    /**
     * @var array<int, class-string<\App\Services\Suppliers\Connectors\SupplierConnectorInterface>>
     */
    public array $adapters = [];
}

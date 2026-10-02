<?php

namespace App\Services\Suppliers\Connectors;

use App\DTO\Suppliers\ConnectionTestResult;

/**
 * Fornecedor cujas ofertas são cadastradas manualmente no painel.
 * Não há nada a buscar externamente; pedidos são enviados pelo operador.
 */
class ManualSupplierConnector extends AbstractSupplierConnector
{
    protected function capabilities(): array
    {
        return [self::CAPABILITY_TEST_CONNECTION];
    }

    public function testConnection(): ConnectionTestResult
    {
        return ConnectionTestResult::ok('Fornecedor com cadastro manual: não há conexão externa a testar.');
    }
}

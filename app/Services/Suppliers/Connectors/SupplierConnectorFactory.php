<?php

namespace App\Services\Suppliers\Connectors;

use App\Enums\SupplierIntegrationType;
use Config\SupplierConnectors;
use InvalidArgumentException;

/**
 * Resolve o conector adequado para um fornecedor.
 */
class SupplierConnectorFactory
{
    public function __construct(private readonly SupplierConnectors $config = new SupplierConnectors())
    {
    }

    /**
     * @param array{id: int|string, name?: string, integration_type: string} $supplier
     * @param array{file_path?: string}                                      $options
     */
    public function make(array $supplier, array $options = []): SupplierConnectorInterface
    {
        $supplierId = (int) $supplier['id'];

        if (isset($this->config->adapters[$supplierId])) {
            $class = $this->config->adapters[$supplierId];

            return new $class($supplier, $options);
        }

        $type = SupplierIntegrationType::tryFrom($supplier['integration_type'])
            ?? throw new InvalidArgumentException("Tipo de integração desconhecido: {$supplier['integration_type']}");

        return match ($type) {
            SupplierIntegrationType::Manual => new ManualSupplierConnector(),
            SupplierIntegrationType::Csv    => new CsvSupplierConnector($options['file_path'] ?? throw new InvalidArgumentException('Informe o arquivo CSV a ser importado.')),
            SupplierIntegrationType::Api,
            SupplierIntegrationType::Xml    => throw new UnsupportedOperationException(sprintf(
                'O fornecedor "%s" usa integração via %s, mas nenhum adapter foi registrado para ele em Config\SupplierConnectors.',
                $supplier['name'] ?? $supplierId,
                $type->label(),
            )),
        };
    }

    /**
     * Adapter registrado para o fornecedor que saiba criar pedidos, ou null
     * (manual e CSV não enviam pedidos: o operador faz a compra).
     */
    public function orderConnector(array $supplier): ?SupplierConnectorInterface
    {
        $class = $this->config->adapters[(int) $supplier['id']] ?? null;
        if ($class === null) {
            return null;
        }

        $connector = new $class($supplier, []);

        return $connector->supports(SupplierConnectorInterface::CAPABILITY_CREATE_ORDER) ? $connector : null;
    }

    /**
     * Conector de CSV para importação avulsa, independente do tipo de integração
     * principal do fornecedor (útil para cargas iniciais).
     */
    public function csv(string $filePath): CsvSupplierConnector
    {
        return new CsvSupplierConnector($filePath);
    }
}

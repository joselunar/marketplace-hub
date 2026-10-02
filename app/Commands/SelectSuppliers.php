<?php

namespace App\Commands;

use App\Exceptions\BusinessRuleException;
use App\Services\Suppliers\SupplierSelectionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Recalcula a seleção de fornecedores. Indicado para rodar no cron, já que a
 * confiabilidade depende de uma janela de tempo e muda mesmo sem edições.
 */
class SelectSuppliers extends BaseCommand
{
    protected $group       = 'Marketplace Hub';
    protected $name        = 'suppliers:select';
    protected $description = 'Recalcula o fornecedor principal e as reservas dos produtos.';
    protected $usage       = 'suppliers:select [--product <id>] [--supplier <id>]';
    protected $options     = [
        '--product'  => 'Recalcula somente o produto informado.',
        '--supplier' => 'Recalcula somente os produtos com ofertas do fornecedor informado.',
    ];

    public function run(array $params)
    {
        $service    = service('supplierSelection');
        $productId  = CLI::getOption('product');
        $supplierId = CLI::getOption('supplier');
        $started    = microtime(true);

        try {
            if ($productId !== null) {
                if (! ctype_digit((string) $productId)) {
                    CLI::error('Informe um ID de produto válido.');

                    return EXIT_USER_INPUT;
                }
                $stats = $service->selectForProducts([(int) $productId], SupplierSelectionService::TRIGGER_COMMAND);
            } elseif ($supplierId !== null) {
                if (! ctype_digit((string) $supplierId)) {
                    CLI::error('Informe um ID de fornecedor válido.');

                    return EXIT_USER_INPUT;
                }
                $stats = $service->selectForSupplier((int) $supplierId, SupplierSelectionService::TRIGGER_COMMAND);
            } else {
                $stats = $service->selectAll(SupplierSelectionService::TRIGGER_COMMAND);
            }
        } catch (BusinessRuleException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write(sprintf(
            '%d produto(s) avaliado(s), %d troca(s) de fornecedor principal em %.1fs.',
            $stats['evaluated'],
            $stats['changed'],
            microtime(true) - $started,
        ), 'green');

        return EXIT_SUCCESS;
    }
}

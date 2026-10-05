<?php

namespace App\Commands;

use App\Exceptions\BusinessRuleException;
use App\Models\MarketplaceAccountModel;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreAuthService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Importa os pedidos atualizados nos últimos dias, como rede de segurança
 * para notificações perdidas (o Mercado Livre desiste após uma hora).
 */
class ImportMercadoLivreOrders extends BaseCommand
{
    protected $group       = 'Vitrine Hub';
    protected $name        = 'meli:import-orders';
    protected $description = 'Importa os pedidos recentes das contas conectadas do Mercado Livre.';
    protected $usage       = 'meli:import-orders [--days <n>] [--account <id>]';
    protected $options     = [
        '--days'    => 'Pedidos atualizados nos últimos N dias (1 a 30, padrão 2).',
        '--account' => 'Importa somente a conta informada.',
    ];

    public function run(array $params)
    {
        $days      = $params['days'] ?? CLI::getOption('days') ?? '2';
        $accountId = $params['account'] ?? CLI::getOption('account');

        if (! ctype_digit((string) $days) || (int) $days < 1 || (int) $days > 30) {
            CLI::error('Informe de 1 a 30 dias.');

            return EXIT_USER_INPUT;
        }
        if ($accountId !== null && ! ctype_digit((string) $accountId)) {
            CLI::error('Informe um ID de conta válido.');

            return EXIT_USER_INPUT;
        }

        $meli = service('mercadoLivre');
        $ids  = $accountId !== null
            ? [(int) $accountId]
            : array_map('intval', array_column(array_filter(
                $meli->accounts()->listFor(MercadoLivreAuthService::SLUG),
                static fn (array $account): bool => $account['status'] === MarketplaceAccountModel::STATUS_CONNECTED && (int) $account['active'] === 1,
            ), 'id'));

        if ($ids === []) {
            CLI::write('Nenhuma conta do Mercado Livre conectada.', 'yellow');

            return EXIT_SUCCESS;
        }

        $failures = 0;
        foreach ($ids as $id) {
            try {
                $stats = service('mercadoLivreOrders')->importRecent($id, (int) $days);
                CLI::write(sprintf('Conta #%d: %d novo(s), %d atualizado(s), %d sem mudança, %d com erro.', $id, $stats['created'], $stats['updated'], $stats['unchanged'], $stats['failed']), $stats['failed'] > 0 ? 'yellow' : 'green');
                foreach (array_slice($stats['errors'], 0, 5) as $error) {
                    CLI::write('  - ' . $error, 'yellow');
                }
                $failures += $stats['failed'] > 0 ? 1 : 0;
            } catch (BusinessRuleException $e) {
                $failures++;
                CLI::error(sprintf('Conta #%d: %s', $id, $e->getMessage()));
            }
        }

        return $failures > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}

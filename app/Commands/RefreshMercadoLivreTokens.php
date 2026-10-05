<?php

namespace App\Commands;

use App\Exceptions\BusinessRuleException;
use App\Models\MarketplaceAccountModel;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreAuthService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\I18n\Time;

/**
 * Renova os tokens do Mercado Livre que estão perto de vencer. O access token
 * dura 6 horas; rodando a cada 30 minutos no cron, nenhuma conta chega a vencer.
 */
class RefreshMercadoLivreTokens extends BaseCommand
{
    protected $group       = 'Vitrine Hub';
    protected $name        = 'meli:refresh-token';
    protected $description = 'Renova os access tokens do Mercado Livre próximos do vencimento.';
    protected $usage       = 'meli:refresh-token [--account <id>] [--force]';
    protected $options     = [
        '--account' => 'Renova somente a conta informada.',
        '--force'   => 'Renova mesmo que o token ainda esteja longe de vencer.',
    ];

    public function run(array $params)
    {
        $meli      = service('mercadoLivre');
        $accountId = $params['account'] ?? CLI::getOption('account');
        $force     = array_key_exists('force', $params) || CLI::getOption('force') !== null;

        if (! $meli->config()->isConfigured()) {
            CLI::error('Integração com o Mercado Livre não configurada: preencha ' . implode(', ', $meli->config()->missingSettings()) . ' no .env.');

            return EXIT_ERROR;
        }

        $auth        = $meli->auth();
        $marketplace = $meli->accounts()->marketplace(MercadoLivreAuthService::SLUG);
        $model       = new MarketplaceAccountModel();

        if ($accountId !== null) {
            if (! ctype_digit((string) $accountId)) {
                CLI::error('Informe um ID de conta válido.');

                return EXIT_USER_INPUT;
            }
            $ids = [(int) $accountId];
        } else {
            $query = $force
                ? $model->where('status', MarketplaceAccountModel::STATUS_CONNECTED)->where('active', 1)
                : $model->expiringBefore(date('Y-m-d H:i:s', Time::now()->getTimestamp() + $meli->config()->refreshBeforeSeconds));
            $ids = array_map('intval', array_column($query->forMarketplace((int) $marketplace['id'])->select('marketplace_accounts.id')->findAll(), 'id'));
        }

        if ($ids === []) {
            CLI::write('Nenhum token precisa de renovação agora.', 'green');

            return EXIT_SUCCESS;
        }

        $failures = 0;
        foreach ($ids as $id) {
            try {
                $account = $auth->refresh($id, $force);
                CLI::write(sprintf('Conta #%d (%s): token válido até %s.', $id, $account['name'], date('d/m/Y H:i', strtotime($account['token_expires_at']))), 'green');
            } catch (BusinessRuleException $e) {
                $failures++;
                CLI::error(sprintf('Conta #%d: %s', $id, $e->getMessage()));
            }
        }

        CLI::write(sprintf('%d conta(s) processada(s), %d falha(s).', count($ids), $failures), $failures > 0 ? 'yellow' : 'green');

        return $failures > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}

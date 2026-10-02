<?php

namespace Config;

use App\Jobs\ImportMarketplaceOrdersJob;
use App\Jobs\ProcessMarketplaceNotificationsJob;
use App\Jobs\RecalculateSupplierSelectionJob;
use App\Jobs\RefreshMarketplaceTokenJob;
use App\Jobs\SyncListingPriceJob;
use App\Jobs\SyncListingStockJob;
use App\Jobs\SyncSupplierPricesJob;
use App\Jobs\SyncSupplierStockJob;
use CodeIgniter\Config\BaseConfig;

/**
 * Fila de jobs e tarefas agendadas.
 *
 * Um único cron por minuto basta:
 *     php spark schedule:run && php spark jobs:work
 * O agendador enfileira as tarefas vencidas e o worker executa a fila.
 */
class Automation extends BaseConfig
{
    /**
     * Tipo do job => classe que o executa.
     *
     * @var array<string, class-string<\App\Jobs\JobInterface>>
     */
    public array $jobs = [
        SyncSupplierStockJob::TYPE               => SyncSupplierStockJob::class,
        SyncSupplierPricesJob::TYPE              => SyncSupplierPricesJob::class,
        RecalculateSupplierSelectionJob::TYPE    => RecalculateSupplierSelectionJob::class,
        SyncListingStockJob::TYPE                => SyncListingStockJob::class,
        SyncListingPriceJob::TYPE                => SyncListingPriceJob::class,
        RefreshMarketplaceTokenJob::TYPE         => RefreshMarketplaceTokenJob::class,
        ProcessMarketplaceNotificationsJob::TYPE => ProcessMarketplaceNotificationsJob::class,
        ImportMarketplaceOrdersJob::TYPE         => ImportMarketplaceOrdersJob::class,
    ];

    /**
     * Tarefas do agendador. "interval" em minutos ou "setting" com a chave
     * de configuração do intervalo (0 desliga); "requires" é uma configuração
     * booleana que precisa estar ligada. As tarefas de marketplace passam por
     * todos os adaptadores com a capacidade correspondente.
     *
     * @var array<string, array{label: string, interval?: int, setting?: string, requires?: string}>
     */
    public array $tasks = [
        'marketplace_notifications' => ['label' => 'Processar notificações dos marketplaces', 'interval' => 1],
        'marketplace_tokens'        => ['label' => 'Renovar tokens dos marketplaces', 'interval' => 15],
        'supplier_selection'        => ['label' => 'Recalcular fornecedores e preços', 'interval' => 60],
        'supplier_stock'            => ['label' => 'Sincronizar estoque dos fornecedores', 'setting' => 'sync.supplier_stock_interval'],
        'supplier_prices'           => ['label' => 'Sincronizar custos dos fornecedores', 'setting' => 'sync.supplier_price_interval'],
        'listing_stock'             => ['label' => 'Conferir estoque dos anúncios', 'setting' => 'sync.marketplace_stock_interval', 'requires' => 'sync.auto_update_stock'],
        'listing_prices'            => ['label' => 'Conferir preço dos anúncios', 'setting' => 'sync.marketplace_price_interval', 'requires' => 'sync.auto_update_price'],
        'marketplace_orders'        => ['label' => 'Importar pedidos recentes', 'setting' => 'sync.orders_import_interval'],
    ];

    /**
     * Tentativas por job; a espera entre elas dobra a partir de retryBaseSeconds.
     */
    public int $maxAttempts      = 5;
    public int $retryBaseSeconds = 60;
    public int $retryMaxSeconds  = 3600;

    /**
     * Job "em execução" há mais tempo que isso (processo interrompido) volta para a fila.
     */
    public int $staleSeconds = 900;

    /**
     * Limites de cada execução do worker (cabe no intervalo de um minuto do cron).
     */
    public int $workerMaxJobs    = 200;
    public int $workerMaxSeconds = 50;

    /**
     * Sem execução do agendador há mais que isso, o dashboard avisa que o cron parou.
     */
    public int $schedulerAlertMinutes = 10;

    /**
     * Jobs concluídos e cancelados mais antigos que isso são apagados pelo agendador.
     */
    public int $keepFinishedDays = 30;
}

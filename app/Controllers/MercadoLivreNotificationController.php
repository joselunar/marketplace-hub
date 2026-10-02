<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\WebhookEventModel;
use CodeIgniter\HTTP\RedirectResponse;
use Config\MercadoLivre;

/**
 * Notificações recebidas do Mercado Livre e o processamento da fila.
 */
class MercadoLivreNotificationController extends BaseController
{
    private const HOME = 'marketplaces/mercadolivre/notifications';

    public function index(): string
    {
        $filters = $this->filters(['status', 'topic', 'q']);
        $model   = new WebhookEventModel();
        $counts  = (new WebhookEventModel())->countsByStatus();
        $events  = $model->withAccount()
            ->applyFilters($filters)
            ->orderBy('webhook_events.id', 'DESC')
            ->paginate($this->perPage());

        return view('mercadolivre/notifications', [
            'title'       => 'Notificações do Mercado Livre',
            'tab'         => 'notifications',
            'events'      => $events,
            'pager'       => $model->pager,
            'filters'     => $filters,
            'counts'      => $counts,
            'webhookUrl'  => site_url('webhooks/mercadolivre'),
            'ipFilter'    => config(MercadoLivre::class)->webhookAllowedIps !== [],
            'breadcrumbs' => ['Marketplaces' => null, 'Mercado Livre' => 'marketplaces/mercadolivre', 'Notificações' => null],
        ]);
    }

    public function process(): RedirectResponse
    {
        $stats = service('mercadoLivreNotifications')->processPending(50);

        if ($stats['processed'] + $stats['failed'] + $stats['retry'] === 0) {
            return redirect()->to(self::HOME)->with('info', 'Nenhuma notificação na fila.');
        }

        $message = sprintf('%d processada(s), %d com falha, %d voltaram para a fila.', $stats['processed'], $stats['failed'], $stats['retry']);

        return redirect()->to(self::HOME)->with($stats['failed'] + $stats['retry'] > 0 ? 'warning' : 'success', $message);
    }

    public function retry(int $id): RedirectResponse
    {
        try {
            service('mercadoLivreNotifications')->retry($id);
        } catch (BusinessRuleException $e) {
            return redirect()->to(self::HOME)->with('error', $e->getMessage());
        }

        return redirect()->to(self::HOME)->with('success', 'Notificação devolvida à fila. Use "Processar fila" para executá-la agora.');
    }
}

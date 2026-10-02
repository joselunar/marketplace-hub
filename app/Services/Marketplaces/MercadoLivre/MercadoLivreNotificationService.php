<?php

namespace App\Services\Marketplaces\MercadoLivre;

use App\Exceptions\BusinessRuleException;
use App\Models\MarketplaceAccountModel;
use App\Models\MarketplaceListingModel;
use App\Models\WebhookEventModel as Events;
use CodeIgniter\Database\BaseConnection;
use Config\MercadoLivre;

/**
 * Notificações do Mercado Livre (documentação "Notificações").
 *
 * O recebimento só valida e grava, para responder HTTP 200 em menos de
 * 500 ms como a documentação exige. O processamento consulta o recurso
 * indicado (GET /orders/{id}, GET /items/{id}) depois, pelo comando
 * meli:process-notifications ou pela tela de notificações.
 *
 * Idempotência: a mesma notificação reenviada não gera outra linha
 * (chave pelo _id ou por tópico + recurso + envio), e o processamento
 * sempre lê o estado atual do recurso, então repeti-lo não duplica nada.
 */
class MercadoLivreNotificationService
{
    public const PROVIDER = 'mercadolivre';

    public const TOPIC_ORDERS = 'orders_v2';
    public const TOPIC_ITEMS  = 'items';

    /**
     * Tópicos tratados; os demais são guardados como ignorados.
     */
    public const HANDLED_TOPICS = [self::TOPIC_ORDERS, self::TOPIC_ITEMS];

    private readonly MercadoLivre $config;
    private readonly BaseConnection $db;

    public function __construct(
        ?MercadoLivre $config = null,
        private readonly ?MercadoLivreOrderService $orders = null,
        private readonly ?MercadoLivreProductService $listings = null,
        private readonly Events $events = new Events(),
        private readonly MarketplaceAccountModel $accounts = new MarketplaceAccountModel(),
    ) {
        $this->config = $config ?? config(MercadoLivre::class);
        $this->db     = $this->events->db;
    }

    /**
     * Valida e grava a notificação.
     *
     * @return array{status: int, message: string, event_id: int|null}
     */
    public function receive(array $payload): array
    {
        $topic         = $payload['topic'] ?? null;
        $resource      = $payload['resource'] ?? null;
        $userId        = $this->digits($payload['user_id'] ?? null);
        $applicationId = $this->digits($payload['application_id'] ?? null);

        if (! is_string($topic) || ! preg_match('/^[a-z0-9_\-]{1,60}$/', $topic)
            || ! is_string($resource) || ! preg_match('/^[\x21-\x7E]{1,255}$/', $resource)
            || $userId === null) {
            return ['status' => 400, 'message' => 'Notificação inválida.', 'event_id' => null];
        }

        if ($this->config->clientId !== '' && $applicationId !== $this->config->clientId) {
            return ['status' => 403, 'message' => 'Notificação de outro aplicativo.', 'event_id' => null];
        }

        $account        = $this->accountFor($userId);
        $notificationId = $payload['_id'] ?? $payload['id'] ?? null;
        $notificationId = is_string($notificationId) && preg_match('/^[A-Za-z0-9\-]{1,64}$/', $notificationId) ? $notificationId : null;
        $sent           = is_string($payload['sent'] ?? null) ? mb_substr($payload['sent'], 0, 40) : '';
        $now            = date('Y-m-d H:i:s');

        [$status, $message] = match (true) {
            $account === null                              => [Events::STATUS_IGNORED, 'Nenhuma conta conectada com o usuário ' . $userId . '.'],
            ! in_array($topic, self::HANDLED_TOPICS, true) => [Events::STATUS_IGNORED, 'Tópico não tratado pelo sistema.'],
            default                                        => [Events::STATUS_PENDING, null],
        };

        $this->db->table('webhook_events')->ignore(true)->insert([
            'provider'               => self::PROVIDER,
            'marketplace_account_id' => $account['id'] ?? null,
            'notification_id'        => $notificationId,
            'dedupe_key'             => sha1(self::PROVIDER . '|' . ($notificationId ?? $topic . '|' . $resource . '|' . $userId . '|' . $sent)),
            'topic'                  => $topic,
            'resource'               => $resource,
            'user_id'                => $userId,
            'application_id'         => $applicationId,
            'attempts'               => is_numeric($payload['attempts'] ?? null) ? max(1, min(1000, (int) $payload['attempts'])) : 1,
            'status'                 => $status,
            'message'                => $message,
            'payload'                => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'sent_at'                => $this->dateTime($sent),
            'received_at'            => $now,
            'processed_at'           => $status === Events::STATUS_IGNORED ? $now : null,
        ]);

        if ($this->db->affectedRows() === 0) {
            return ['status' => 200, 'message' => 'Notificação já recebida.', 'event_id' => null];
        }

        return ['status' => 200, 'message' => 'Recebida.', 'event_id' => (int) $this->db->insertID()];
    }

    /**
     * Processa as notificações na fila, da mais antiga para a mais nova.
     *
     * @return array{processed: int, failed: int, retry: int}
     */
    public function processPending(int $limit = 50): array
    {
        $this->releaseStale();

        $stats = ['processed' => 0, 'failed' => 0, 'retry' => 0];
        $ids   = array_column($this->events->select('id')->where('provider', self::PROVIDER)->where('status', Events::STATUS_PENDING)->orderBy('id')->findAll(max(1, min(500, $limit))), 'id');

        foreach ($ids as $id) {
            $result = $this->process((int) $id);
            if ($result !== null) {
                $stats[$result]++;
            }
        }

        return $stats;
    }

    /**
     * Notificações esperando processamento (inclusive as em andamento).
     */
    public function pendingCount(): int
    {
        return $this->events->where('provider', self::PROVIDER)
            ->whereIn('status', [Events::STATUS_PENDING, Events::STATUS_PROCESSING])
            ->countAllResults();
    }

    /**
     * Devolve à fila uma notificação que falhou ou foi ignorada.
     */
    public function retry(int $eventId): void
    {
        $event = $this->events->find($eventId);
        if ($event === null) {
            throw new BusinessRuleException('Notificação não encontrada.');
        }
        if (! in_array($event['status'], [Events::STATUS_FAILED, Events::STATUS_IGNORED], true)) {
            throw new BusinessRuleException('Só notificações com falha ou ignoradas voltam para a fila.');
        }

        $account = $event['marketplace_account_id'] ?? null;
        if ($account === null) {
            $found   = $this->accountFor($event['user_id']);
            $account = $found['id'] ?? null;
        }
        if ($account === null) {
            throw new BusinessRuleException('Ainda não há conta conectada com o usuário ' . $event['user_id'] . '.');
        }
        if (! in_array($event['topic'], self::HANDLED_TOPICS, true)) {
            throw new BusinessRuleException('O tópico "' . $event['topic'] . '" não é tratado pelo sistema.');
        }

        $this->events->update($eventId, [
            'marketplace_account_id' => $account,
            'status'                 => Events::STATUS_PENDING,
            'process_attempts'       => 0,
            'message'                => null,
            'locked_at'              => null,
            'processed_at'           => null,
        ]);
    }

    /**
     * @return 'processed'|'failed'|'retry'|null null quando outro processo já pegou a notificação
     */
    private function process(int $id): ?string
    {
        $claimedAt = date('Y-m-d H:i:s');
        $this->events->builder()
            ->where('id', $id)
            ->where('status', Events::STATUS_PENDING)
            ->set('process_attempts', 'process_attempts + 1', false)
            ->set(['status' => Events::STATUS_PROCESSING, 'locked_at' => $claimedAt])
            ->update();

        if ($this->db->affectedRows() !== 1) {
            return null;
        }

        $event = $this->events->find($id);

        try {
            $message = $this->handle($event);
        } catch (MercadoLivreApiException $e) {
            // 403/404: o recurso não existe ou não é da conta; tentar de novo não muda nada.
            return $this->failure($event, $e->userMessage(), in_array($e->status, [403, 404], true));
        } catch (BusinessRuleException $e) {
            return $this->failure($event, $e->getMessage(), false);
        }

        $now = date('Y-m-d H:i:s');
        $this->events->update($id, ['status' => Events::STATUS_PROCESSED, 'message' => mb_substr($message, 0, 1000), 'locked_at' => null, 'processed_at' => $now]);

        // Notificações do mesmo recurso que chegaram antes já estão cobertas pela leitura de agora.
        $this->events->builder()
            ->where('status', Events::STATUS_PENDING)
            ->where('marketplace_account_id', $event['marketplace_account_id'])
            ->where('topic', $event['topic'])
            ->where('resource', $event['resource'])
            ->where('received_at <=', $claimedAt)
            ->update(['status' => Events::STATUS_PROCESSED, 'message' => 'Coberta pelo processamento da notificação #' . $id . '.', 'processed_at' => $now]);

        return 'processed';
    }

    private function handle(array $event): string
    {
        $accountId = (int) $event['marketplace_account_id'];

        if ($event['topic'] === self::TOPIC_ORDERS) {
            if (! preg_match('#^/orders/(\d{1,30})$#', $event['resource'], $match)) {
                throw new BusinessRuleException('Recurso de pedido inesperado: ' . $event['resource']);
            }

            $result = $this->orderService()->import($accountId, $match[1]);

            return sprintf('Pedido %s %s.', $match[1], $result['created'] ? 'importado' : ($result['changed'] ? 'atualizado' : 'sem alterações'));
        }

        if (! preg_match('#^/items/([A-Z0-9]{1,30})$#', $event['resource'], $match)) {
            throw new BusinessRuleException('Recurso de anúncio inesperado: ' . $event['resource']);
        }

        $listing = (new MarketplaceListingModel())
            ->where('marketplace_account_id', $accountId)
            ->where('external_listing_id', $match[1])
            ->first();

        if ($listing === null) {
            return 'Anúncio ' . $match[1] . ' não foi publicado pelo sistema; nada a atualizar.';
        }

        $this->listingService()->sync((int) $listing['id']);

        return 'Anúncio ' . $match[1] . ' sincronizado.';
    }

    /**
     * @return 'failed'|'retry'
     */
    private function failure(array $event, string $message, bool $permanent): string
    {
        $failed = $permanent || (int) $event['process_attempts'] >= $this->config->notificationMaxAttempts;

        $this->events->update((int) $event['id'], [
            'status'       => $failed ? Events::STATUS_FAILED : Events::STATUS_PENDING,
            'message'      => mb_substr(($failed ? '' : 'Nova tentativa na próxima execução. ') . $message, 0, 1000),
            'locked_at'    => null,
            'processed_at' => $failed ? date('Y-m-d H:i:s') : null,
        ]);

        return $failed ? 'failed' : 'retry';
    }

    /**
     * Notificações presas em "processando" (processo interrompido) voltam para a fila.
     */
    private function releaseStale(): void
    {
        $this->events->builder()
            ->where('provider', self::PROVIDER)
            ->where('status', Events::STATUS_PROCESSING)
            ->where('locked_at <', date('Y-m-d H:i:s', time() - $this->config->notificationLockSeconds))
            ->update(['status' => Events::STATUS_PENDING, 'locked_at' => null]);
    }

    private function accountFor(string $externalUserId): ?array
    {
        return $this->accounts->select('marketplace_accounts.id')
            ->join('marketplaces', 'marketplaces.id = marketplace_accounts.marketplace_id')
            ->where('marketplaces.slug', MercadoLivreAuthService::SLUG)
            ->where('marketplace_accounts.external_user_id', $externalUserId)
            ->where('marketplace_accounts.active', 1)
            ->first();
    }

    private function digits(mixed $value): ?string
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }
        if (is_float($value) && $value > 0 && floor($value) === $value) {
            return sprintf('%.0F', $value);
        }

        return is_string($value) && preg_match('/^\d{1,30}$/', $value) ? $value : null;
    }

    private function dateTime(string $value): ?string
    {
        $timestamp = $value !== '' ? strtotime($value) : false;

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function orderService(): MercadoLivreOrderService
    {
        return $this->orders ?? service('mercadoLivreOrders');
    }

    private function listingService(): MercadoLivreProductService
    {
        return $this->listings ?? service('mercadoLivreProducts');
    }
}

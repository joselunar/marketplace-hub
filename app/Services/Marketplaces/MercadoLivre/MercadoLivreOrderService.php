<?php

namespace App\Services\Marketplaces\MercadoLivre;

use App\Exceptions\BusinessRuleException;
use App\Models\SyncLogModel;
use App\Services\Orders\OrderService;
use App\Support\Decimal;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Leitura de pedidos do Mercado Livre (documentação "Orders"):
 * - GET /orders/{id}, usado ao processar a notificação orders_v2;
 * - GET /orders/search?seller=..., usado na importação manual.
 *
 * Os dados da API são validados e normalizados aqui; a gravação, a
 * reserva do fornecedor e o lucro ficam no OrderService.
 */
class MercadoLivreOrderService
{
    private const PAGE_SIZE = 50;

    private readonly MercadoLivreService $meli;
    private readonly OrderService $orders;

    public function __construct(
        ?MercadoLivreService $meli = null,
        ?OrderService $orders = null,
        private readonly SyncLogModel $syncLogs = new SyncLogModel(),
    ) {
        $this->meli   = $meli ?? service('mercadoLivre');
        $this->orders = $orders ?? service('orders');
    }

    /**
     * Busca o pedido na API e grava o estado atual.
     *
     * @return array{order_id: int, created: bool, changed: bool}
     */
    public function import(int $accountId, string $externalOrderId): array
    {
        if (! ctype_digit($externalOrderId)) {
            throw new BusinessRuleException('ID de pedido inválido: ' . $externalOrderId);
        }

        $account = $this->meli->resolveAccount($accountId);
        $order   = $this->meli->get($accountId, '/orders/' . $externalOrderId);

        return $this->orders->ingest($account, $this->normalize($order, $account));
    }

    /**
     * Pedidos atualizados nos últimos dias. A busca do vendedor não traz
     * pedidos cancelados (documentação "Consultar orders"); cancelamentos
     * chegam pela notificação orders_v2.
     *
     * @return array{created: int, updated: int, unchanged: int, failed: int, errors: list<string>}
     */
    public function importRecent(int $accountId, int $days, ?int $userId = null): array
    {
        $days      = max(1, min(30, $days));
        $account   = $this->meli->resolveAccount($accountId);
        $startedAt = date('Y-m-d H:i:s');
        $from      = (new DateTimeImmutable('-' . $days . ' days'))->format('Y-m-d\TH:i:s.000P');
        $stats     = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'errors' => []];

        try {
            for ($page = 0; $page < $this->meli->config()->orderImportMaxPages; $page++) {
                $response = $this->meli->get($accountId, '/orders/search', [
                    'seller'                       => (string) $account['external_user_id'],
                    'order.date_last_updated.from' => $from,
                    'sort'                         => 'date_desc',
                    'limit'                        => self::PAGE_SIZE,
                    'offset'                       => $page * self::PAGE_SIZE,
                ]);
                $results = is_array($response['results'] ?? null) ? $response['results'] : [];

                foreach ($results as $order) {
                    try {
                        $result = $this->orders->ingest($account, $this->normalize(is_array($order) ? $order : [], $account), $userId);
                        $stats[$result['created'] ? 'created' : ($result['changed'] ? 'updated' : 'unchanged')]++;
                    } catch (BusinessRuleException $e) {
                        $stats['failed']++;
                        $stats['errors'][] = $e->getMessage();
                    }
                }

                $total = (int) ($response['paging']['total'] ?? 0);
                if ($results === [] || ($page + 1) * self::PAGE_SIZE >= $total) {
                    break;
                }
            }
        } catch (MercadoLivreApiException $e) {
            $this->syncLog((int) $account['id'], SyncLogModel::STATUS_ERROR, 'Falha ao importar pedidos: ' . $e->userMessage(), $startedAt);

            throw new BusinessRuleException('Falha ao importar pedidos: ' . $e->userMessage(), 0, $e);
        }

        $message = sprintf(
            'Importação de pedidos (%d dia(s)): %d novo(s), %d atualizado(s), %d sem mudança, %d com erro.',
            $days,
            $stats['created'],
            $stats['updated'],
            $stats['unchanged'],
            $stats['failed'],
        );
        $this->syncLog((int) $account['id'], $stats['failed'] > 0 ? SyncLogModel::STATUS_WARNING : SyncLogModel::STATUS_SUCCESS, $message, $startedAt, array_slice($stats['errors'], 0, 10));

        return $stats;
    }

    /**
     * Converte a order da API no formato gravado pelo OrderService,
     * recusando respostas sem os campos essenciais.
     */
    public function normalize(array $order, array $account): array
    {
        $id = $this->idString($order['id'] ?? null);
        if ($id === null) {
            throw new BusinessRuleException('Pedido sem ID na resposta do Mercado Livre.');
        }

        $sellerId = $this->idString($order['seller']['id'] ?? null);
        if ($sellerId !== null && $sellerId !== (string) $account['external_user_id']) {
            throw new BusinessRuleException(sprintf('O pedido %s não pertence à conta %s.', $id, $account['name']));
        }

        $status = is_string($order['status'] ?? null) && preg_match('/^[a-z_]{1,30}$/', $order['status']) ? $order['status'] : null;
        $date   = $this->dateTime($order['date_created'] ?? null);
        if ($status === null || $date === null) {
            throw new BusinessRuleException(sprintf('Pedido %s sem status ou data de criação válidos.', $id));
        }

        $items = [];
        foreach (is_array($order['order_items'] ?? null) ? $order['order_items'] : [] as $row) {
            $item = $this->item(is_array($row) ? $row : []);
            if ($item === null) {
                throw new BusinessRuleException(sprintf('Pedido %s com item inválido na resposta do Mercado Livre.', $id));
            }
            $items[] = $item;
        }
        if ($items === []) {
            throw new BusinessRuleException(sprintf('Pedido %s sem itens.', $id));
        }

        $tags = array_values(array_filter(is_array($order['tags'] ?? null) ? $order['tags'] : [], static fn ($tag): bool => is_string($tag) && preg_match('/^[a-z0-9_]{1,40}$/', $tag) === 1));

        return [
            'external_order_id'   => $id,
            'pack_id'             => $this->idString($order['pack_id'] ?? null),
            'shipping_id'         => $this->idString($order['shipping']['id'] ?? null),
            'buyer_external_id'   => $this->idString($order['buyer']['id'] ?? null),
            'status'              => $status,
            'status_detail'       => $this->statusDetail($order['status_detail'] ?? null),
            'currency_id'         => is_string($order['currency_id'] ?? null) ? mb_substr($order['currency_id'], 0, 5) : 'BRL',
            'total_amount'        => $this->money($order['total_amount'] ?? null) ?? '0.00',
            'paid_amount'         => $this->money($order['paid_amount'] ?? null),
            'shipping_amount'     => $this->shippingAmount($order['payments'] ?? null),
            'tags'                => $tags,
            'fraud_risk'          => in_array('fraud_risk_detected', $tags, true),
            'ordered_at'          => $date,
            'closed_at'           => $this->dateTime($order['date_closed'] ?? null),
            'external_updated_at' => $this->dateTime($order['last_updated'] ?? null),
            'items'               => $items,
        ];
    }

    /**
     * sale_fee vem por unidade; o item guarda a tarifa total (× quantidade).
     */
    private function item(array $row): ?array
    {
        $itemId   = $row['item']['id'] ?? null;
        $quantity = $row['quantity'] ?? null;
        $price    = $this->money($row['unit_price'] ?? null);

        if (! is_string($itemId) || ! preg_match('/^[A-Z0-9]{1,30}$/', $itemId) || ! is_numeric($quantity) || (int) $quantity < 1 || $price === null) {
            return null;
        }

        $quantity = (int) $quantity;
        $fee      = $this->money($row['sale_fee'] ?? null);
        $sku      = $row['item']['seller_sku'] ?? null;
        $title    = $row['item']['title'] ?? null;

        return [
            'external_item_id' => $itemId,
            'variation_id'     => $this->idString($row['item']['variation_id'] ?? null) ?? '',
            'title'            => is_string($title) && trim($title) !== '' ? mb_substr(trim($title), 0, 255) : $itemId,
            'seller_sku'       => is_string($sku) && trim($sku) !== '' ? mb_substr(trim($sku), 0, 100) : null,
            'quantity'         => $quantity,
            'unit_price'       => $price,
            'sale_fee'         => $fee === null ? null : Decimal::round(bcmul($fee, (string) $quantity, 4), 2),
        ];
    }

    /**
     * Frete pago pelo comprador nos pagamentos aprovados.
     */
    private function shippingAmount(mixed $payments): ?string
    {
        if (! is_array($payments) || $payments === []) {
            return null;
        }

        $total = '0.00';
        foreach ($payments as $payment) {
            if (is_array($payment) && ($payment['status'] ?? null) === 'approved') {
                $total = bcadd($total, $this->money($payment['shipping_cost'] ?? null) ?? '0', 2);
            }
        }

        return $total;
    }

    private function statusDetail(mixed $detail): ?string
    {
        if (is_array($detail)) {
            $detail = $detail['description'] ?? $detail['code'] ?? null;
        }

        return is_string($detail) && trim($detail) !== '' ? mb_substr(trim($detail), 0, 255) : null;
    }

    private function money(mixed $value): ?string
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $amount = Decimal::round(is_float($value) ? sprintf('%.6F', $value) : (string) $value, 2);

        return bccomp($amount, '0', 2) < 0 ? null : $amount;
    }

    private function idString(mixed $value): ?string
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }
        if (is_float($value) && $value > 0 && floor($value) === $value) {
            return sprintf('%.0F', $value);
        }

        return is_string($value) && preg_match('/^\d{1,30}$/', $value) ? $value : null;
    }

    /**
     * Datas ISO 8601 da API convertidas para o fuso da aplicação.
     */
    private function dateTime(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (Exception) {
            return null;
        }
    }

    private function syncLog(int $accountId, string $status, string $message, string $startedAt, ?array $payload = null): void
    {
        $this->syncLogs->insert([
            'integration_type' => MercadoLivreAuthService::SYNC_INTEGRATION,
            'integration_id'   => $accountId,
            'action'           => 'orders_import',
            'status'           => $status,
            'message'          => mb_substr($message, 0, 1000),
            'payload'          => $payload !== null && $payload !== [] ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'started_at'       => $startedAt,
            'finished_at'      => date('Y-m-d H:i:s'),
        ]);
    }
}

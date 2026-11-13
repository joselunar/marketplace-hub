<?php

namespace App\Services\Fiscal;

use App\Exceptions\BusinessRuleException;
use App\Models\FiscalInvoiceEventModel;
use App\Models\FiscalInvoiceModel;
use App\Models\OrderModel;

/**
 * Envio do XML autorizado ao Mercado Livre.
 * Bloqueado em homologação e sem autorização explícita.
 */
class MercadoLivreFiscalDocumentService
{
    public function __construct(
        private readonly FiscalGate $gate = new FiscalGate(),
        private readonly FiscalInvoiceModel $invoices = new FiscalInvoiceModel(),
        private readonly FiscalDocumentStorage $storage = new FiscalDocumentStorage(),
        private readonly OrderModel $orders = new OrderModel(),
        private readonly FiscalInvoiceEventModel $events = new FiscalInvoiceEventModel(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function sendAuthorizedXml(int $invoiceId, ?int $userId = null): array
    {
        $this->gate->assertCanSendToMercadoLivre();
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null || $invoice['status'] !== FiscalInvoiceModel::STATUS_AUTHORIZED) {
            throw new BusinessRuleException('Somente NF-e autorizada em produção pode ir ao Mercado Livre.');
        }
        if (empty($invoice['xml_authorized_path'])) {
            throw new BusinessRuleException('XML autorizado ausente.');
        }
        if (($invoice['ml_send_status'] ?? '') === 'enviado') {
            throw new BusinessRuleException('XML já enviado ao Mercado Livre para este documento.');
        }

        $shippingId = $invoice['shipping_id'] ?? null;
        if ($shippingId === null || $shippingId === '') {
            $order = $invoice['order_id'] ? $this->orders->find((int) $invoice['order_id']) : null;
            $shippingId = $order['shipping_id'] ?? null;
        }
        if ($shippingId === null || $shippingId === '') {
            throw new BusinessRuleException('Pedido sem shipping_id para anexar a NF-e.');
        }

        $accountId = (int) ($invoice['marketplace_account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new BusinessRuleException('Conta Mercado Livre não vinculada ao documento.');
        }

        $xml = $this->storage->read((string) $invoice['xml_authorized_path']);
        $meli = service('mercadoLivre');
        $site = 'MLB';

        // Endpoint oficial de importação para liberação de etiqueta (modelo 55).
        $path = '/shipments/' . rawurlencode((string) $shippingId) . '/invoice_data/?siteId=' . rawurlencode($site);

        try {
            $meli->postXml($accountId, $path, $xml);
            $this->invoices->update($invoiceId, [
                'ml_send_status'  => 'enviado',
                'ml_send_message' => 'XML enviado ao endpoint invoice_data.',
                'ml_sent_at'      => date('Y-m-d H:i:s'),
            ]);
            $this->events->insert([
                'fiscal_invoice_id' => $invoiceId,
                'event_type'        => 'ml_send',
                'message'           => 'XML autorizado enviado ao Mercado Livre.',
                'created_by'        => $userId,
            ]);
        } catch (\Throwable $e) {
            $this->invoices->update($invoiceId, [
                'ml_send_status'  => 'erro',
                'ml_send_message' => mb_substr($e->getMessage(), 0, 500),
            ]);
            $this->events->insert([
                'fiscal_invoice_id' => $invoiceId,
                'event_type'        => 'ml_send_error',
                'message'           => mb_substr($e->getMessage(), 0, 1000),
                'created_by'        => $userId,
            ]);
            throw new BusinessRuleException('Falha ao enviar XML ao Mercado Livre: ' . $e->getMessage());
        }

        return $this->invoices->find($invoiceId);
    }
}

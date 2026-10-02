<?php

namespace App\Services\Suppliers;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\SupplierOfferModel;
use App\Services\Audit\AuditService;
use App\Support\Decimal;
use App\Support\Input;

class SupplierOfferService
{
    public function __construct(
        private readonly SupplierOfferModel $offers = new SupplierOfferModel(),
        private readonly ?AuditService $audit = null,
        private readonly ?SupplierSelectionService $selection = null,
    ) {
    }

    public function create(array $input): int
    {
        $data = $this->prepare($input);
        $this->guardUniqueSku((int) ($data['supplier_id'] ?? 0), (string) ($data['supplier_sku'] ?? ''));

        if (! $this->offers->insert($data)) {
            throw new ValidationException($this->offers->errors());
        }
        $id = (int) $this->offers->getInsertID();

        $this->audit()->log('create', 'supplier_offer', $id, null, $data);

        if ($data['product_id'] !== null) {
            $this->selection()->select($data['product_id'], SupplierSelectionService::TRIGGER_OFFER);
        }

        return $id;
    }

    public function update(int $id, array $input): void
    {
        $current = $this->findOrFail($id);
        $data    = $this->prepare($input, $id);
        $this->guardUniqueSku((int) ($data['supplier_id'] ?? 0), (string) ($data['supplier_sku'] ?? ''), $id);

        if (! $this->offers->update($id, $data)) {
            throw new ValidationException($this->offers->errors());
        }

        $this->audit()->log('update', 'supplier_offer', $id, $current, $data);
        $this->reselectAfterProductChange($id, $this->productId($current), $data['product_id']);
    }

    public function delete(int $id): void
    {
        $current  = $this->findOrFail($id);
        $snapshot = $this->selection()->snapshotIfSelected($id);

        $this->offers->delete($id);
        $this->audit()->log('delete', 'supplier_offer', $id, $current, null);

        $productId = $this->productId($current);

        if ($productId !== null) {
            $this->selection()->select($productId, SupplierSelectionService::TRIGGER_OFFER, null, $snapshot);
        }
    }

    /**
     * Vincula (ou desvincula, com null) a oferta a um produto do catálogo.
     */
    public function link(int $id, ?int $productId): void
    {
        $current = $this->findOrFail($id);
        $data    = ['id' => $id, 'supplier_id' => $current['supplier_id'], 'product_id' => $productId];

        if (! $this->offers->update($id, $data)) {
            throw new ValidationException($this->offers->errors());
        }

        $this->audit()->log($productId === null ? 'unlink' : 'link', 'supplier_offer', $id, $current, $data);
        $this->reselectAfterProductChange($id, $this->productId($current), $productId);
    }

    public function findOrFail(int $id): array
    {
        $offer = $this->offers->find($id);

        if ($offer === null) {
            throw new BusinessRuleException('Oferta não encontrada.');
        }

        return $offer;
    }

    /**
     * Recalcula o produto antigo (se a oferta saiu dele) e o atual.
     * A oferta só perde o resultado da avaliação depois que o produto antigo
     * foi recalculado, para o histórico ainda enxergar o score anterior.
     */
    private function reselectAfterProductChange(int $offerId, ?int $oldProductId, ?int $newProductId): void
    {
        if ($oldProductId !== null && $oldProductId !== $newProductId) {
            $this->selection()->select($oldProductId, SupplierSelectionService::TRIGGER_OFFER);
            $this->selection()->resetOffer($offerId);
        }

        if ($newProductId !== null) {
            $this->selection()->select($newProductId, SupplierSelectionService::TRIGGER_OFFER);
        }
    }

    private function productId(array $offer): ?int
    {
        return $offer['product_id'] !== null ? (int) $offer['product_id'] : null;
    }

    private function prepare(array $input, ?int $id = null): array
    {
        $data = Input::only($input, [
            'supplier_id', 'product_id', 'supplier_sku', 'supplier_product_name', 'ean',
            'cost_price', 'stock_quantity', 'shipping_cost', 'shipping_days', 'blocked_reason',
        ]);

        $data['supplier_id']    = isset($data['supplier_id']) ? (int) $data['supplier_id'] : null;
        $data['product_id']     = isset($data['product_id']) ? (int) $data['product_id'] : null;
        $data['ean']            = Input::digits($data['ean'] ?? null);
        $data['cost_price']     = $this->money($data['cost_price'] ?? null);
        $data['shipping_cost']  = $this->money($data['shipping_cost'] ?? null) ?? '0.00';
        $data['stock_quantity'] = $data['stock_quantity'] ?? null;
        $data['shipping_days']  = $data['shipping_days'] ?? null;
        $data['active']         = Input::flag($input['active'] ?? 0);
        $data['blocked']        = Input::flag($input['blocked'] ?? 0);

        if ($data['blocked'] === 1 && ($data['blocked_reason'] ?? null) === null) {
            throw new ValidationException(['blocked_reason' => 'Informe o motivo do bloqueio.']);
        }

        if ($data['blocked'] === 0) {
            $data['blocked_reason'] = null;
        }

        if ($id !== null) {
            $data['id'] = $id;
        }

        return $data;
    }

    /**
     * Normaliza dinheiro; se não for numérico mantém o original para a validação apontar o erro.
     */
    private function money(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return Decimal::normalize($value) ?? $value;
    }

    private function guardUniqueSku(int $supplierId, string $supplierSku, ?int $ignoreId = null): void
    {
        if ($supplierId === 0 || $supplierSku === '') {
            return;
        }

        $existing = $this->offers->findBySupplierSku($supplierId, $supplierSku);

        if ($existing !== null && (int) $existing['id'] !== $ignoreId) {
            throw new ValidationException(['supplier_sku' => 'Este fornecedor já possui uma oferta com este SKU.']);
        }
    }

    private function audit(): AuditService
    {
        return $this->audit ?? service('audit');
    }

    private function selection(): SupplierSelectionService
    {
        return $this->selection ?? service('supplierSelection');
    }
}

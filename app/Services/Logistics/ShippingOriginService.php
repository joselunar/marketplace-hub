<?php

namespace App\Services\Logistics;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\ProductModel;
use App\Models\ShippingOriginModel;
use App\Models\SupplierModel;
use App\Models\SupplierOfferModel;
/**
 * Resolve CEP de expedição: SKU/produto → oferta → CD/fornecedor.
 * Não inventa CEP; sem confirmação a cotação permanece indicativa.
 */
class ShippingOriginService
{
    public function __construct(
        private readonly ShippingOriginModel $origins = new ShippingOriginModel(),
        private readonly SupplierOfferModel $offers = new SupplierOfferModel(),
        private readonly SupplierModel $suppliers = new SupplierModel(),
        private readonly ProductModel $products = new ProductModel(),
    ) {
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed>|null $offer
     *
     * @return array{
     *   postal_code: ?string,
     *   uf: ?string,
     *   city: ?string,
     *   label: ?string,
     *   source_type: ?string,
     *   evidence_note: ?string,
     *   level: string,
     *   confirmed: bool,
     *   note: string
     * }
     */
    public function resolve(array $product, ?array $offer = null): array
    {
        $productId  = (int) ($product['id'] ?? 0);
        $supplierId = (int) ($offer['supplier_id'] ?? 0);

        if ($productId > 0) {
            $skuOrigins = $this->origins->forProduct($productId);
            if ($skuOrigins !== []) {
                return $this->normalizeRow($skuOrigins[0], 'sku', true);
            }
        }

        if (! empty($offer['origin_postal_code'])) {
            $zip = $this->digits((string) $offer['origin_postal_code']);
            if ($zip !== null) {
                $source = (string) ($offer['origin_source'] ?? ShippingOriginModel::SOURCE_FORNECEDOR);
                $confirmed = in_array($source, [
                    ShippingOriginModel::SOURCE_DOCUMENTAL,
                    ShippingOriginModel::SOURCE_FORNECEDOR,
                    ShippingOriginModel::SOURCE_MANUAL,
                ], true);

                return [
                    'postal_code'   => $zip,
                    'uf'            => $this->uf($offer['origin_uf'] ?? $product['origin_uf'] ?? null),
                    'city'          => null,
                    'label'         => 'CEP da oferta/SKU',
                    'source_type'   => $source !== '' ? $source : ShippingOriginModel::SOURCE_FORNECEDOR,
                    'evidence_note' => $offer['origin_evidence'] ?? null,
                    'level'         => 'offer',
                    'confirmed'     => $confirmed && $source !== ShippingOriginModel::SOURCE_ESTIMADO,
                    'note'          => 'CEP da oferta do fornecedor'
                        . ($confirmed ? ' com origem registrada.' : ' sem confirmação documental plena.'),
                ];
            }
        }

        if ($supplierId > 0) {
            $supplierOrigins = $this->origins->forSupplier($supplierId);
            if ($supplierOrigins !== []) {
                return $this->normalizeRow($supplierOrigins[0], 'supplier', true);
            }
        }

        return [
            'postal_code'   => null,
            'uf'            => $this->uf($offer['origin_uf'] ?? $product['origin_uf'] ?? null),
            'city'          => null,
            'label'         => null,
            'source_type'   => null,
            'evidence_note' => null,
            'level'         => 'missing',
            'confirmed'     => false,
            'note'          => 'Sem CEP de expedição confirmado por SKU, oferta ou fornecedor/CD. Frete permanece estimativo (pode usar endereço da conta VITRINEHUB).',
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, ?int $userId = null): int
    {
        $data = $this->prepare($input, $userId);

        if (! $this->origins->insert($data)) {
            throw new ValidationException($this->origins->errors() ?: ['Falha ao salvar origem de expedição.']);
        }

        return (int) $this->origins->getInsertID();
    }

    public function deactivate(int $id): void
    {
        $row = $this->origins->find($id);
        if ($row === null) {
            throw new BusinessRuleException('Origem de expedição não encontrada.');
        }
        $this->origins->update($id, ['active' => 0]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function prepare(array $input, ?int $userId): array
    {
        $supplierId = self::positiveInt($input['supplier_id'] ?? null);
        $productId  = self::positiveInt($input['product_id'] ?? null);
        if ($supplierId === null && $productId === null) {
            throw new ValidationException(['supplier_id' => 'Informe o fornecedor ou o produto (SKU).']);
        }
        if ($supplierId !== null && $this->suppliers->find($supplierId) === null) {
            throw new BusinessRuleException('Fornecedor inválido.');
        }
        if ($productId !== null && $this->products->find($productId) === null) {
            throw new BusinessRuleException('Produto inválido.');
        }

        $zip = $this->digits((string) ($input['postal_code'] ?? ''));
        if ($zip === null) {
            throw new ValidationException(['postal_code' => 'Informe um CEP válido com 8 dígitos.']);
        }

        $source = (string) ($input['source_type'] ?? ShippingOriginModel::SOURCE_DOCUMENTAL);
        if (! isset(ShippingOriginModel::SOURCE_LABELS[$source])) {
            throw new ValidationException(['source_type' => 'Origem da informação inválida.']);
        }

        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '') {
            $label = $productId !== null ? 'Expedição do SKU' : 'Centro de distribuição';
        }

        $confirmedAt = null;
        if ($source === ShippingOriginModel::SOURCE_DOCUMENTAL || ! empty($input['confirmed'])) {
            $confirmedAt = date('Y-m-d H:i:s');
        }

        return [
            'supplier_id'   => $supplierId,
            'product_id'    => $productId,
            'label'         => mb_substr($label, 0, 120),
            'postal_code'   => $zip,
            'city'          => ($v = trim((string) ($input['city'] ?? ''))) !== '' ? mb_substr($v, 0, 120) : null,
            'uf'            => $this->uf($input['uf'] ?? null),
            'source_type'   => $source,
            'evidence_note' => ($v = trim((string) ($input['evidence_note'] ?? ''))) !== '' ? mb_substr($v, 0, 500) : null,
            'evidence_url'  => ($v = trim((string) ($input['evidence_url'] ?? ''))) !== '' ? mb_substr($v, 0, 500) : null,
            'confirmed_at'  => $confirmedAt,
            'active'        => 1,
            'created_by'    => $userId,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row, string $level, bool $fromTable): array
    {
        $source = (string) ($row['source_type'] ?? ShippingOriginModel::SOURCE_MANUAL);
        $confirmed = $fromTable
            && $source !== ShippingOriginModel::SOURCE_ESTIMADO
            && (! empty($row['confirmed_at']) || $source === ShippingOriginModel::SOURCE_DOCUMENTAL);

        return [
            'postal_code'   => $this->digits((string) ($row['postal_code'] ?? '')),
            'uf'            => $this->uf($row['uf'] ?? null),
            'city'          => $row['city'] ?? null,
            'label'         => $row['label'] ?? null,
            'source_type'   => $source,
            'evidence_note' => $row['evidence_note'] ?? null,
            'level'         => $level,
            'confirmed'     => $confirmed,
            'note'          => sprintf(
                'CEP %s (%s) via %s%s.',
                $row['postal_code'] ?? '',
                ShippingOriginModel::SOURCE_LABELS[$source] ?? $source,
                $level === 'sku' ? 'SKU/produto' : 'fornecedor/CD',
                $confirmed ? ', confirmado' : ', ainda sem confirmação plena',
            ),
        ];
    }

    private function digits(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) === 8 ? $digits : null;
    }

    private function uf(mixed $value): ?string
    {
        $uf = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{2}$/', $uf) === 1 ? $uf : null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}

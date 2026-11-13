<?php

namespace App\Services\Profitability;

use App\Exceptions\BusinessRuleException;
use App\Models\ProductModel;
use App\Models\SupplierCategoryMappingModel;
use App\Models\SupplierOfferModel;
use App\Services\Audit\AuditService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreAuthService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreCategoryService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreService;
use App\Services\Suppliers\DropifyImportService;
use Throwable;

/**
 * Sugestão e confirmação de categoria MLB para rentabilidade.
 * Sugestões nunca entram sozinhas no cálculo — só status confirmed/manual/supplier_mapping.
 */
class ProfitabilityCategoryService
{
    public const SOURCE_SUGGESTED = 'suggested';
    public const SOURCE_CONFIRMED = 'confirmed';
    public const SOURCE_MANUAL    = 'manual';
    public const SOURCE_MAPPING   = 'supplier_mapping';

    /** Fontes que autorizam cotação de tarifa. */
    public const CONFIRMED_SOURCES = [
        self::SOURCE_CONFIRMED,
        self::SOURCE_MANUAL,
        self::SOURCE_MAPPING,
    ];

    public function __construct(
        private readonly ProductModel $products = new ProductModel(),
        private readonly SupplierOfferModel $offers = new SupplierOfferModel(),
        private readonly SupplierCategoryMappingModel $mappings = new SupplierCategoryMappingModel(),
        private readonly ?MercadoLivreService $meli = null,
        private readonly ?MercadoLivreCategoryService $categories = null,
        private readonly ?AuditService $audit = null,
    ) {
    }

    public function resolveAccount(?int $accountId = null): ?array
    {
        try {
            $meli = $this->meli ?? service('mercadoLivre');
            if ($accountId !== null) {
                return $meli->resolveAccount($accountId);
            }
            foreach ($meli->accounts()->listFor(MercadoLivreAuthService::SLUG) as $account) {
                if (($account['status'] ?? '') === 'connected' && (int) ($account['active'] ?? 0) === 1) {
                    return $account;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Categoria MLB utilizável na cotação (apenas confirmadas).
     */
    public function resolvedCategoryId(array $product, ?array $offer, ?array $account): ?string
    {
        $source = (string) ($product['marketplace_category_source'] ?? '');
        $id     = trim((string) ($product['marketplace_category_id'] ?? ''));
        if ($id !== '' && in_array($source, self::CONFIRMED_SOURCES, true)) {
            return strtoupper($id);
        }

        if ($account === null) {
            return null;
        }

        $provider = (string) ($product['source_provider'] ?? DropifyImportService::PROVIDER);
        $sourceCat = trim((string) ($offer['source_category'] ?? ''));
        if ($sourceCat !== '') {
            $map = $this->mappings->findConfirmed((int) $account['marketplace_id'], $provider, $sourceCat);
            if ($map !== null) {
                return strtoupper((string) $map['external_category_id']);
            }
        }

        if (! empty($product['category_id'])) {
            $row = db_connect()->table('marketplace_category_mappings')
                ->where('marketplace_id', (int) $account['marketplace_id'])
                ->where('category_id', (int) $product['category_id'])
                ->get()
                ->getRowArray();
            if (is_string($row['external_category_id'] ?? null) && $row['external_category_id'] !== '') {
                return strtoupper($row['external_category_id']);
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function suggest(array $product, ?array $offer = null, ?array $account = null): array
    {
        $account ??= $this->resolveAccount();
        if ($account === null) {
            return [];
        }

        $title = trim((string) ($product['name'] ?? ''));
        $sourceCategory = trim((string) ($offer['source_category'] ?? ''));
        if ($sourceCategory !== '' && mb_strlen($title . ' ' . $sourceCategory) <= 150) {
            $query = trim($title . ' ' . $sourceCategory);
        } else {
            $query = $title;
        }

        if (mb_strlen($query) < 3) {
            return [];
        }

        try {
            return ($this->categories ?? new MercadoLivreCategoryService())->predict($account, $query, 5);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Confirma categoria MLB no produto e, opcionalmente, no mapeamento do fornecedor.
     */
    public function confirm(
        int $productId,
        string $categoryId,
        ?int $userId,
        bool $rememberSupplierMapping = true,
        string $origin = self::SOURCE_CONFIRMED,
    ): array {
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new BusinessRuleException('Produto não encontrado.');
        }

        $account = $this->resolveAccount();
        if ($account === null) {
            throw new BusinessRuleException('Nenhuma conta Mercado Livre conectada para validar a categoria.');
        }

        $categoryId = strtoupper(trim($categoryId));
        $detail = ($this->categories ?? new MercadoLivreCategoryService())->category($account, $categoryId);
        if (! ($detail['is_leaf'] ?? false)) {
            throw new BusinessRuleException('Selecione uma categoria folha do Mercado Livre (sem subcategorias).');
        }

        $name   = (string) ($detail['name'] ?? $categoryId);
        $origin = in_array($origin, [self::SOURCE_CONFIRMED, self::SOURCE_MANUAL], true) ? $origin : self::SOURCE_CONFIRMED;

        $this->products->update($productId, [
            'marketplace_category_id'          => $categoryId,
            'marketplace_category_name'        => $name,
            'marketplace_category_source'      => $origin,
            'marketplace_category_confirmed_at'=> date('Y-m-d H:i:s'),
        ]);

        $offer = $this->offers->where('product_id', $productId)->orderBy('id', 'DESC')->first();
        $sourceCategory = trim((string) ($offer['source_category'] ?? ''));
        $provider = (string) ($product['source_provider'] ?? DropifyImportService::PROVIDER);

        if ($rememberSupplierMapping && $sourceCategory !== '') {
            $existing = $this->mappings
                ->where('marketplace_id', (int) $account['marketplace_id'])
                ->where('provider_code', $provider)
                ->where('source_category', $sourceCategory)
                ->first();

            $payload = [
                'marketplace_id'         => (int) $account['marketplace_id'],
                'provider_code'          => $provider,
                'source_category'        => $sourceCategory,
                'external_category_id'   => $categoryId,
                'external_category_name' => $name,
                'status'                 => SupplierCategoryMappingModel::STATUS_CONFIRMED,
                'origin'                 => $origin,
                'confirmed_by'           => $userId,
                'confirmed_at'           => date('Y-m-d H:i:s'),
            ];

            if ($existing === null) {
                $this->mappings->insert($payload);
            } else {
                $this->mappings->update((int) $existing['id'], $payload);
            }
        }

        ($this->audit ?? service('audit'))->log(
            'profitability_category_confirm',
            'product',
            $productId,
            [
                'marketplace_category_id' => $product['marketplace_category_id'] ?? null,
                'source'                  => $product['marketplace_category_source'] ?? null,
            ],
            [
                'marketplace_category_id'   => $categoryId,
                'marketplace_category_name' => $name,
                'origin'                    => $origin,
                'source_category'           => $sourceCategory !== '' ? $sourceCategory : null,
            ],
            $userId,
        );

        return $this->products->find($productId);
    }

    /**
     * Produtos que compartilham a mesma categoria de fornecedor (para recálculo em lote).
     *
     * @return list<int>
     */
    public function productIdsForSourceCategory(string $providerCode, string $sourceCategory): array
    {
        $sourceCategory = trim($sourceCategory);
        if ($sourceCategory === '') {
            return [];
        }

        $rows = $this->offers
            ->select('supplier_offers.product_id')
            ->join('products', 'products.id = supplier_offers.product_id')
            ->where('supplier_offers.source_category', $sourceCategory)
            ->where('products.source_provider', $providerCode)
            ->where('supplier_offers.product_id IS NOT NULL', null, false)
            ->findAll();

        return array_values(array_unique(array_map(static fn (array $r): int => (int) $r['product_id'], $rows)));
    }
}

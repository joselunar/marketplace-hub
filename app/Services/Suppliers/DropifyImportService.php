<?php

namespace App\Services\Suppliers;

use App\DTO\Suppliers\DropifyCatalogItem;
use App\Exceptions\BusinessRuleException;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use App\Models\SupplierImportModel;
use App\Models\SupplierOfferModel;
use App\Models\SyncLogModel;
use App\Services\Audit\AuditService;
use App\Services\Profitability\ProfitabilityService;
use App\Services\Settings\SettingsService;
use App\Services\Suppliers\Connectors\DropifyCsvConnector;
use App\Support\Decimal;
use InvalidArgumentException;
use Throwable;

/**
 * Importação idempotente do catálogo Dropify via CSV.
 * Cria/atualiza produto interno (SKU DFY-{sku}) e oferta do fornecedor.
 */
class DropifyImportService
{
    public const PROVIDER = 'dropify';

    private const MAX_STORED_ERRORS = 200;

    public function __construct(
        private readonly SupplierService $suppliers = new SupplierService(),
        private readonly ProductModel $products = new ProductModel(),
        private readonly ProductImageModel $images = new ProductImageModel(),
        private readonly SupplierOfferModel $offers = new SupplierOfferModel(),
        private readonly SupplierImportModel $imports = new SupplierImportModel(),
        private readonly SyncLogModel $syncLogs = new SyncLogModel(),
        private readonly ?AuditService $audit = null,
        private readonly ?SettingsService $settings = null,
        private readonly ?ProfitabilityService $profitability = null,
        private readonly ?SupplierSelectionService $selection = null,
    ) {
    }

    public function importCsv(int $supplierId, string $filePath, string $originalName, ?int $userId = null): int
    {
        $settings = $this->settings ?? service('settings');
        if (! $settings->getBool('dropify.csv_enabled')) {
            throw new BusinessRuleException('A importação CSV Dropify está desabilitada nas configurações.');
        }

        $supplier = $this->suppliers->findOrFail($supplierId);
        if (($supplier['provider_code'] ?? null) !== self::PROVIDER && ($supplier['provider_code'] ?? '') !== '') {
            throw new BusinessRuleException('Este fornecedor não está marcado como Dropify (provider_code).');
        }

        $startedAt = date('Y-m-d H:i:s');
        $this->imports->insert([
            'supplier_id'       => $supplierId,
            'user_id'           => $userId,
            'source'            => 'dropify_csv',
            'original_filename' => mb_substr($originalName, 0, 255),
            'status'            => SupplierImportModel::STATUS_PROCESSING,
            'started_at'        => $startedAt,
        ]);
        $importId = (int) $this->imports->getInsertID();

        try {
            $parsed = (new DropifyCsvConnector($filePath))->parse();
            $stats  = $this->apply($supplierId, $parsed['items']);
        } catch (InvalidArgumentException $e) {
            $this->finishFailed($importId, $supplierId, $startedAt, $e->getMessage());

            throw new BusinessRuleException($e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            log_message('error', 'Falha ao importar CSV Dropify do fornecedor {id}: {message}', [
                'id' => $supplierId, 'message' => $e->getMessage(),
            ]);
            $this->finishFailed($importId, $supplierId, $startedAt, 'Erro inesperado ao processar o arquivo Dropify.');

            throw $e;
        }

        if (($supplier['provider_code'] ?? null) !== self::PROVIDER) {
            $this->offers->db->table('suppliers')->where('id', $supplierId)->update(['provider_code' => self::PROVIDER]);
        }

        $errorCount = count($parsed['errors']);
        $status     = $errorCount > 0
            ? SupplierImportModel::STATUS_COMPLETED_WITH_ERRORS
            : SupplierImportModel::STATUS_COMPLETED;

        $this->imports->update($importId, [
            'status'        => $status,
            'total_rows'    => $parsed['total'],
            'created_count' => $stats['created'],
            'updated_count' => $stats['updated'],
            'linked_count'  => $stats['linked'],
            'error_count'   => $errorCount,
            'errors'        => $errorCount > 0
                ? json_encode(array_slice($parsed['errors'], 0, self::MAX_STORED_ERRORS), JSON_UNESCAPED_UNICODE)
                : null,
            'finished_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->syncLogs->insert([
            'integration_type' => SupplierService::SYNC_INTEGRATION_TYPE,
            'integration_id'   => $supplierId,
            'action'           => 'import_dropify_csv',
            'status'           => $errorCount > 0 ? SyncLogModel::STATUS_WARNING : SyncLogModel::STATUS_SUCCESS,
            'message'          => sprintf(
                'Importação Dropify CSV: %d linhas, %d criados, %d atualizados, %d vinculados, %d erros.',
                $parsed['total'],
                $stats['created'],
                $stats['updated'],
                $stats['linked'],
                $errorCount,
            ),
            'payload'          => json_encode(['import_id' => $importId] + $stats, JSON_UNESCAPED_UNICODE),
            'started_at'       => $startedAt,
            'finished_at'      => date('Y-m-d H:i:s'),
        ]);

        ($this->audit ?? service('audit'))->log(
            'import_dropify_csv',
            'supplier',
            $supplierId,
            null,
            ['import_id' => $importId, 'file' => $originalName] + $stats,
            $userId,
        );

        ($this->selection ?? service('supplierSelection'))->selectForSupplier(
            $supplierId,
            SupplierSelectionService::TRIGGER_IMPORT,
            $userId,
        );

        $profitability = $this->profitability ?? service('profitability');
        foreach ($stats['product_ids'] as $productId) {
            try {
                $profitability->analyzeProduct($productId, $userId, 'import_dropify_csv');
            } catch (Throwable $e) {
                log_message('warning', 'Análise de rentabilidade falhou para produto {id}: {msg}', [
                    'id' => $productId, 'msg' => $e->getMessage(),
                ]);
            }
        }

        return $importId;
    }

    /**
     * @param list<DropifyCatalogItem> $items
     *
     * @return array{created: int, updated: int, linked: int, product_ids: list<int>}
     */
    public function apply(int $supplierId, array $items): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'linked' => 0, 'product_ids' => []];
        $now   = date('Y-m-d H:i:s');
        $db    = $this->offers->db;

        $existingOffers = [];
        foreach ($this->offers->select('id, supplier_sku, product_id')->where('supplier_id', $supplierId)->findAll() as $row) {
            $existingOffers[mb_strtolower($row['supplier_sku'])] = $row;
        }

        $db->transException(true)->transStart();

        foreach ($items as $item) {
            $skuKey   = mb_strtolower($item->supplierSku);
            $existing = $existingOffers[$skuKey] ?? null;
            $internal = 'DFY-' . $item->supplierSku;

            $productId = $existing['product_id'] ?? null;
            if ($productId === null) {
                $found = $this->products->where('sku', $internal)->first();
                if ($found !== null) {
                    $productId = (int) $found['id'];
                    $stats['linked']++;
                }
            }

            if ($productId === null) {
                $productData = $this->productData($item, $internal, true);
                $this->products->skipValidation(true)->insert($productData);
                $productId = (int) $this->products->getInsertID();
                $this->syncImages($productId, $item->imageUrls);
                $stats['created']++;
            } else {
                $productData = $this->productData($item, $internal, false);
                $this->products->skipValidation(true)->update($productId, $productData);
                $this->syncImages($productId, $item->imageUrls);
                $stats['updated']++;
            }

            $offerData = $this->offerData($item, $productId, $now);
            if ($existing === null) {
                $this->offers->skipValidation(true)->insert($offerData + [
                    'supplier_id' => $supplierId,
                    'active'      => 1,
                ]);
            } else {
                $this->offers->skipValidation(true)->update((int) $existing['id'], $offerData);
            }

            $stats['product_ids'][] = $productId;
        }

        $db->transComplete();
        $this->products->skipValidation(false);
        $this->offers->skipValidation(false);

        $stats['product_ids'] = array_values(array_unique($stats['product_ids']));

        return $stats;
    }

    private function productData(DropifyCatalogItem $item, string $sku, bool $creating): array
    {
        $weightKg = $item->weightGrams !== null
            ? Decimal::round(bcdiv((string) $item->weightGrams, '1000', 6), 3)
            : null;

        $data = [
            'sku'             => $sku,
            'ean'             => $item->ean,
            'name'            => $item->name,
            'description'     => $item->description ?? $item->shortDescription,
            'brand'           => $item->brand,
            'weight'          => $weightKg,
            'height'          => $item->heightCm,
            'width'           => $item->widthCm,
            'length'          => $item->lengthCm,
            'ncm'             => $item->ncm,
            'cest'            => $item->cest,
            'fiscal_origin'   => $item->fiscalOrigin,
            'origin_uf'       => $item->originUf,
            'source_provider' => self::PROVIDER,
            'sale_price'      => $item->suggestedPrice,
            'active'          => 1,
        ];

        if ($creating) {
            $slugBase = $this->slugify($sku . '-' . $item->name);
            $slug     = $slugBase;
            $i        = 1;
            while ($this->products->where('slug', $slug)->countAllResults() > 0) {
                $slug = mb_substr($slugBase, 0, 200) . '-' . $i++;
            }
            $data['slug'] = $slug;
        }

        return $data;
    }

    private function offerData(DropifyCatalogItem $item, int $productId, string $now): array
    {
        return [
            'product_id'            => $productId,
            'supplier_sku'          => $item->supplierSku,
            'supplier_product_name' => $item->name,
            'ean'                   => $item->ean,
            'cost_price'            => $item->costPrice,
            'suggested_price'       => $item->suggestedPrice,
            'stock_quantity'        => $item->stockQuantity,
            'shipping_days'         => $item->shippingDays,
            'weight_grams'          => $item->weightGrams,
            'height_cm'             => $item->heightCm,
            'width_cm'              => $item->widthCm,
            'length_cm'             => $item->lengthCm,
            'ncm'                   => $item->ncm,
            'cest'                  => $item->cest,
            'fiscal_origin'         => $item->fiscalOrigin,
            'origin_uf'             => $item->originUf,
            'origin_postal_code'     => $item->originPostalCode,
            'source_category'       => $item->sourceCategory,
            'availability_status'   => $item->availabilityStatus,
            'short_description'     => $item->shortDescription,
            'image_urls'            => $item->imageUrls !== [] ? json_encode($item->imageUrls, JSON_UNESCAPED_UNICODE) : null,
            'metadata'              => $item->metadata !== [] ? json_encode($item->metadata, JSON_UNESCAPED_UNICODE) : null,
            'last_sync_at'          => $now,
            'active'                => 1,
        ];
    }

    /**
     * @param list<string> $urls
     */
    private function syncImages(int $productId, array $urls): void
    {
        $this->images->where('product_id', $productId)->delete();
        $position = 0;
        foreach (array_slice($urls, 0, 12) as $url) {
            $this->images->insert([
                'product_id' => $productId,
                'url'        => $url,
                'position'   => $position++,
            ]);
        }
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower($value);
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);

        return mb_substr(trim($value, '-'), 0, 220) ?: 'produto';
    }

    private function finishFailed(int $importId, int $supplierId, string $startedAt, string $message): void
    {
        $now = date('Y-m-d H:i:s');
        $this->imports->update($importId, [
            'status'      => SupplierImportModel::STATUS_FAILED,
            'error_count' => 1,
            'errors'      => json_encode([['line' => 0, 'message' => $message]], JSON_UNESCAPED_UNICODE),
            'finished_at' => $now,
        ]);
        $this->syncLogs->insert([
            'integration_type' => SupplierService::SYNC_INTEGRATION_TYPE,
            'integration_id'   => $supplierId,
            'action'           => 'import_dropify_csv',
            'status'           => SyncLogModel::STATUS_ERROR,
            'message'          => $message,
            'payload'          => json_encode(['import_id' => $importId], JSON_UNESCAPED_UNICODE),
            'started_at'       => $startedAt,
            'finished_at'      => $now,
        ]);
    }
}

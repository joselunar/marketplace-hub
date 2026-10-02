<?php

namespace App\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use App\Models\SupplierOfferModel;
use CodeIgniter\HTTP\ResponseInterface;

class ProductController extends BaseApiController
{
    private const LIST_FIELDS = 'products.id, products.sku, products.ean, products.name, products.brand, products.category_id, products.active, '
        . 'products.sale_price, products.suggested_price, products.estimated_margin, products.price_status, products.updated_at';

    public function index(): ResponseInterface
    {
        $model   = new ProductModel();
        $term    = $this->queryString('q');
        $filters = array_filter([
            'sku'         => $this->queryString('sku'),
            'ean'         => $this->queryString('ean'),
            'brand'       => $this->queryString('brand'),
            'category_id' => $this->queryString('category_id'),
            'status'      => $this->queryString('status'),
            'supplier'    => $this->queryString('supplier'),
            'pricing'     => $this->queryString('pricing'),
        ]);

        $model->select(self::LIST_FIELDS)->applyFilters($filters);

        if ($term !== null) {
            $model->groupStart()
                ->like('products.name', $term)
                ->orLike('products.sku', $term, 'after')
                ->orWhere('products.ean', $term)
                ->groupEnd();
        }

        $rows = $model->orderBy('products.name')->paginate($this->perPage());

        return $this->respond($this->paginated($rows, $model->pager));
    }

    public function show(int $id): ResponseInterface
    {
        $product = (new ProductModel())->find($id);

        if ($product === null) {
            return $this->failNotFound('Produto não encontrado.');
        }

        $product['images'] = array_map(
            static fn (array $image) => ['url' => $image['url'], 'position' => (int) $image['position']],
            (new ProductImageModel())->forProduct($id),
        );

        return $this->respond(['data' => $product]);
    }

    public function offers(int $id): ResponseInterface
    {
        if ((new ProductModel())->find($id) === null) {
            return $this->failNotFound('Produto não encontrado.');
        }

        $offers = array_map(static fn (array $offer) => [
            'id'                    => (int) $offer['id'],
            'supplier_id'           => (int) $offer['supplier_id'],
            'supplier_name'         => $offer['supplier_name'],
            'supplier_status'       => $offer['supplier_status'],
            'supplier_sku'          => $offer['supplier_sku'],
            'supplier_product_name' => $offer['supplier_product_name'],
            'cost_price'            => $offer['cost_price'],
            'stock_quantity'        => (int) $offer['stock_quantity'],
            'shipping_cost'         => $offer['shipping_cost'],
            'shipping_days'         => $offer['shipping_days'] !== null ? (int) $offer['shipping_days'] : null,
            'active'                => (int) $offer['active'] === 1,
            'blocked'               => (int) $offer['blocked'] === 1,
            'available_stock'       => $offer['available_stock'] !== null ? (int) $offer['available_stock'] : null,
            'selection_score'       => $offer['selection_score'],
            'selection_rank'        => $offer['selection_rank'] !== null ? (int) $offer['selection_rank'] : null,
            'ineligibility_reason'  => $offer['ineligibility_reason'],
            'last_sync_at'          => $offer['last_sync_at'],
        ], (new SupplierOfferModel())->forProduct($id));

        return $this->respond(['data' => $offers]);
    }

    /**
     * Ranking atual das ofertas do produto, com os motivos de cada posição.
     */
    public function selection(int $id): ResponseInterface
    {
        try {
            $result = service('supplierSelection')->evaluate($id);
        } catch (BusinessRuleException $e) {
            return $this->failNotFound($e->getMessage());
        }

        return $this->respond(['data' => $result->toArray()]);
    }

    /**
     * Preço com o fornecedor principal (ou, sem principal, com a oferta
     * barrada apenas pela margem) e o preço de cada oferta avaliada.
     */
    public function pricing(int $id): ResponseInterface
    {
        try {
            $result = service('supplierSelection')->evaluate($id);
        } catch (BusinessRuleException $e) {
            return $this->failNotFound($e->getMessage());
        }

        $product   = (new ProductModel())->select('sale_price, price_status, pricing_updated_at')->find($id);
        $reference = $result->primary() ?? ($result->blockedOnlyByMargin()[0] ?? null);

        return $this->respond(['data' => [
            'product_id'         => $id,
            'manual_price'       => $product['sale_price'],
            'price_status'       => $product['price_status'],
            'pricing_updated_at' => $product['pricing_updated_at'],
            'margins'            => $result->pricing?->margins->toArray(),
            'supplier_offer_id'  => $reference?->offerId,
            'supplier_name'      => $reference?->supplierName,
            'pricing'            => $reference?->pricing?->toArray(),
            'offers'             => array_map(static fn ($e) => [
                'offer_id'      => $e->offerId,
                'supplier_name' => $e->supplierName,
                'eligible'      => $e->isEligible(),
                'pricing'       => $e->pricing?->toArray(),
            ], $result->evaluations),
        ]]);
    }
}

<?php

namespace App\Services\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use App\Models\SupplierOfferModel;
use App\Services\Audit\AuditService;
use App\Services\Suppliers\SupplierSelectionService;
use App\Support\Decimal;
use App\Support\Input;

class ProductService
{
    public const MAX_IMAGES = 12;

    public function __construct(
        private readonly ProductModel $products = new ProductModel(),
        private readonly ProductImageModel $images = new ProductImageModel(),
        private readonly SupplierOfferModel $offers = new SupplierOfferModel(),
        private readonly ?AuditService $audit = null,
        private readonly ?SupplierSelectionService $selection = null,
    ) {
    }

    /**
     * @param list<string> $imageUrls
     */
    public function create(array $input, array $imageUrls = []): int
    {
        $data   = $this->prepare($input);
        $images = $this->prepareImages($imageUrls);

        $db = $this->products->db;
        $db->transException(true)->transStart();

        if (! $this->products->insert($data)) {
            $db->transRollback();

            throw new ValidationException($this->products->errors());
        }
        $id = (int) $this->products->getInsertID();
        $this->syncImages($id, $images);

        $db->transComplete();

        $this->audit()->log('create', 'product', $id, null, $data + ['images' => $images]);

        return $id;
    }

    /**
     * @param list<string> $imageUrls
     */
    public function update(int $id, array $input, array $imageUrls = []): void
    {
        $current = $this->findOrFail($id);
        $data    = $this->prepare($input, $id, $current['slug']);
        $images  = $this->prepareImages($imageUrls);

        $currentImages = array_column($this->images->forProduct($id), 'url');

        $db = $this->products->db;
        $db->transException(true)->transStart();

        if (! $this->products->update($id, $data)) {
            $db->transRollback();

            throw new ValidationException($this->products->errors());
        }
        $this->syncImages($id, $images);

        $db->transComplete();

        $this->audit()->log(
            'update',
            'product',
            $id,
            $current + ['images' => implode("\n", $currentImages)],
            $data + ['images' => implode("\n", $images)],
        );

        $safety = static fn (mixed $value): ?string => $value === null ? null : (string) $value;

        if ($safety($current['safety_stock'] ?? null) !== $safety($data['safety_stock'] ?? null)) {
            $this->selection()->select($id, SupplierSelectionService::TRIGGER_PRODUCT);
        }
    }

    /**
     * Exclui o produto. As ofertas vinculadas ficam sem vínculo (FK SET NULL)
     * e podem ser relacionadas a outro produto depois.
     */
    public function delete(int $id): void
    {
        $current = $this->findOrFail($id);

        $this->products->delete($id);
        $this->audit()->log('delete', 'product', $id, $current, null);
    }

    public function findOrFail(int $id): array
    {
        $product = $this->products->find($id);

        if ($product === null) {
            throw new BusinessRuleException('Produto não encontrado.');
        }

        return $product;
    }

    public function details(int $id): array
    {
        $product = $this->findOrFail($id);

        return [
            'product'   => $product,
            'images'    => $this->images->forProduct($id),
            'offers'    => $this->offers->forProduct($id),
            'selection' => $this->selection()->evaluate($id),
            'history'   => $this->selection()->history($id),
        ];
    }

    private function prepare(array $input, ?int $id = null, ?string $currentSlug = null): array
    {
        $data = Input::only($input, ['sku', 'ean', 'name', 'description', 'brand', 'category_id', 'weight', 'height', 'width', 'length', 'safety_stock']);

        $data['sku']         = isset($data['sku']) ? strtoupper($data['sku']) : '';
        $data['name']        = $data['name'] ?? '';
        $data['ean']         = Input::digits($data['ean'] ?? null);
        $data['category_id'] = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $data['active']      = Input::flag($input['active'] ?? 0);

        foreach (['height', 'width', 'length'] as $field) {
            $data[$field] = $this->decimalOrRaw($data[$field] ?? null, 2);
        }
        $data['weight'] = $this->decimalOrRaw($data['weight'] ?? null, 3);

        $data['slug'] = $this->uniqueSlug($data['name'], $id, $currentSlug);

        if ($id !== null) {
            $data['id'] = $id;
        }

        return $data;
    }

    /**
     * Mantém o valor original quando não é numérico para a validação apontar o erro.
     */
    private function decimalOrRaw(mixed $value, int $scale): mixed
    {
        if ($value === null) {
            return null;
        }

        return Decimal::normalize($value, $scale) ?? $value;
    }

    private function uniqueSlug(string $name, ?int $id, ?string $currentSlug): string
    {
        $base = Input::slug($name);

        if ($base === '') {
            return '';
        }

        if ($currentSlug !== null && preg_match('/^' . preg_quote($base, '/') . '(-\d+)?$/', $currentSlug)) {
            return $currentSlug;
        }

        $slug   = $base;
        $suffix = 2;

        while (true) {
            $query = $this->products->where('slug', $slug);

            if ($id !== null) {
                $query->where('id !=', $id);
            }

            if ($query->countAllResults() === 0) {
                return $slug;
            }
            $slug = $base . '-' . $suffix++;
        }
    }

    /**
     * @param list<mixed> $urls
     *
     * @return list<string>
     */
    private function prepareImages(array $urls): array
    {
        $clean  = [];
        $errors = [];

        foreach ($urls as $index => $url) {
            $url = is_string($url) ? trim($url) : '';

            if ($url === '') {
                continue;
            }

            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

            if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true) || strlen($url) > 500) {
                $errors['images.' . $index] = 'URL de imagem inválida: ' . mb_strimwidth($url, 0, 80, '…');

                continue;
            }
            $clean[] = $url;
        }

        if (count($clean) > self::MAX_IMAGES) {
            $errors['images'] = 'Informe no máximo ' . self::MAX_IMAGES . ' imagens.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return array_values(array_unique($clean));
    }

    /**
     * @param list<string> $urls
     */
    private function syncImages(int $productId, array $urls): void
    {
        $this->images->where('product_id', $productId)->delete();

        foreach ($urls as $position => $url) {
            $this->images->insert(['product_id' => $productId, 'url' => $url, 'position' => $position]);
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

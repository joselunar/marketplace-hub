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

        $this->guardManualPrice($id, $data);

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

        if ($this->affectsSelection($current, $data)) {
            $this->selection()->select($id, SupplierSelectionService::TRIGGER_PRODUCT);
        }
    }

    /**
     * Recusa um preço manual com o qual nenhuma oferta atinge a margem mínima.
     * Se ao menos um fornecedor a atinge, o motor de seleção passa a usá-lo.
     */
    private function guardManualPrice(int $id, array $data): void
    {
        $price = $data['sale_price'] ?? null;

        if ($price === null || ! is_numeric($price) || bccomp((string) $price, '0', 2) <= 0) {
            return;
        }

        foreach (['desired_margin_percentage', 'minimum_margin_percentage', 'extra_cost'] as $field) {
            if (($data[$field] ?? null) !== null && ! is_numeric($data[$field])) {
                return;
            }
        }

        $preview = $this->selection()->evaluate($id, $data);
        $blocked = $preview->blockedOnlyByMargin();

        if ($preview->primary() !== null || $blocked === []) {
            return;
        }

        usort($blocked, static fn ($a, $b) => bccomp((string) $a->pricing->minimumPrice, (string) $b->pricing->minimumPrice, 2));
        $best = $blocked[0];

        throw new ValidationException([
            'sale_price' => sprintf(
                'Com o preço de R$ %s a margem fica em %s%%, abaixo da mínima de %s%%. Preço mínimo: R$ %s (com %s).',
                number_format((float) $price, 2, ',', '.'),
                number_format((float) $best->pricing->marginPercentage, 1, ',', '.'),
                number_format((float) $best->pricing->margins->minimum, 1, ',', '.'),
                number_format((float) $best->pricing->minimumPrice, 2, ',', '.'),
                $best->supplierName,
            ),
        ]);
    }

    private function affectsSelection(array $current, array $data): bool
    {
        $normalize = static fn (mixed $value): ?string => $value === null || $value === '' ? null
            : (is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') : (string) $value);

        foreach (SupplierSelectionService::PRODUCT_FIELDS as $field) {
            if ($normalize($current[$field] ?? null) !== $normalize($data[$field] ?? null)) {
                return true;
            }
        }

        return false;
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
        $data = Input::only($input, [
            'sku', 'ean', 'name', 'description', 'brand', 'category_id', 'weight', 'height', 'width', 'length', 'safety_stock',
            'desired_margin_percentage', 'minimum_margin_percentage', 'extra_cost', 'sale_price',
        ]);

        $data['sku']         = isset($data['sku']) ? strtoupper($data['sku']) : '';
        $data['name']        = $data['name'] ?? '';
        $data['ean']         = Input::digits($data['ean'] ?? null);
        $data['category_id'] = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $data['active']      = Input::flag($input['active'] ?? 0);

        foreach (['height', 'width', 'length'] as $field) {
            $data[$field] = $this->decimalOrRaw($data[$field] ?? null, 2);
        }
        $data['weight'] = $this->decimalOrRaw($data['weight'] ?? null, 3);

        foreach (['desired_margin_percentage', 'minimum_margin_percentage', 'extra_cost', 'sale_price'] as $field) {
            $data[$field] = $this->decimalOrRaw($data[$field] ?? null, 2);
        }

        $desired = $data['desired_margin_percentage'];
        $minimum = $data['minimum_margin_percentage'];

        if (is_numeric($desired) && is_numeric($minimum) && bccomp((string) $minimum, (string) $desired, 2) > 0) {
            throw new ValidationException(['minimum_margin_percentage' => 'A margem mínima não pode ser maior que a margem desejada.']);
        }

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

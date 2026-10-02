<?php

namespace App\Services\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\CategoryModel;
use App\Models\ProductModel;
use App\Services\Audit\AuditService;
use App\Services\Suppliers\SupplierSelectionService;
use App\Support\Decimal;
use App\Support\Input;

class CategoryService
{
    public function __construct(
        private readonly CategoryModel $categories = new CategoryModel(),
        private readonly ProductModel $products = new ProductModel(),
        private readonly ?AuditService $audit = null,
        private readonly ?SupplierSelectionService $selection = null,
    ) {
    }

    public function create(array $input): int
    {
        $data = $this->prepare($input);

        if (! $this->categories->insert($data)) {
            throw new ValidationException($this->categories->errors());
        }
        $id = (int) $this->categories->getInsertID();

        $this->audit()->log('create', 'category', $id, null, $data);

        return $id;
    }

    /**
     * Mudanças de margem ou de categoria pai alteram as margens herdadas,
     * então os produtos da categoria e das subcategorias são reprecificados.
     *
     * @return array{evaluated: int, changed: int}|null null quando nada precisou ser recalculado
     */
    public function update(int $id, array $input): ?array
    {
        $current = $this->findOrFail($id);
        $data    = $this->prepare($input, $id);

        $this->guardHierarchy($id, $data['parent_id']);

        if (! $this->categories->update($id, $data)) {
            throw new ValidationException($this->categories->errors());
        }

        $this->audit()->log('update', 'category', $id, $current, $data);

        $changed = false;

        foreach (['parent_id', 'desired_margin_percentage', 'minimum_margin_percentage'] as $field) {
            if ($this->normalize($current[$field] ?? null) !== $this->normalize($data[$field] ?? null)) {
                $changed = true;
            }
        }

        if (! $changed) {
            return null;
        }

        return $this->selection()->selectForCategories(
            $this->categories->subtreeIds($id),
            SupplierSelectionService::TRIGGER_CATEGORY,
        );
    }

    public function delete(int $id): void
    {
        $current = $this->findOrFail($id);

        if ($this->categories->where('parent_id', $id)->countAllResults() > 0) {
            throw new BusinessRuleException('Esta categoria possui subcategorias. Mova-as ou exclua-as antes.');
        }

        if ($this->products->where('category_id', $id)->countAllResults() > 0) {
            throw new BusinessRuleException('Esta categoria possui produtos vinculados. Altere a categoria desses produtos ou inative a categoria.');
        }

        $this->categories->delete($id);
        $this->audit()->log('delete', 'category', $id, $current, null);
    }

    public function findOrFail(int $id): array
    {
        $category = $this->categories->find($id);

        if ($category === null) {
            throw new BusinessRuleException('Categoria não encontrada.');
        }

        return $category;
    }

    private function prepare(array $input, ?int $id = null): array
    {
        $data = Input::only($input, ['parent_id', 'name', 'description', 'desired_margin_percentage', 'minimum_margin_percentage']);

        $data['name']      = $data['name'] ?? '';
        $data['slug']      = Input::slug($data['name']);
        $data['parent_id'] = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $data['active']    = Input::flag($input['active'] ?? 0);

        foreach (['desired_margin_percentage', 'minimum_margin_percentage'] as $field) {
            $value        = $data[$field] ?? null;
            $data[$field] = $value === null ? null : (Decimal::normalize($value) ?? $value);
        }

        $desired = $data['desired_margin_percentage'];
        $minimum = $data['minimum_margin_percentage'];

        if (is_numeric($desired) && is_numeric($minimum) && bccomp((string) $minimum, (string) $desired, 2) > 0) {
            throw new ValidationException(['minimum_margin_percentage' => 'A margem mínima não pode ser maior que a margem desejada.']);
        }

        if ($id !== null) {
            $data['id'] = $id;
        }

        return $data;
    }

    private function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? Decimal::round((string) $value, 2) : (string) $value;
    }

    /**
     * Impede que a categoria seja pai de si mesma ou de um ancestral.
     */
    private function guardHierarchy(int $id, ?int $parentId): void
    {
        $guard = 0;

        while ($parentId !== null && $guard++ < 50) {
            if ($parentId === $id) {
                throw new ValidationException(['parent_id' => 'A categoria pai não pode ser a própria categoria nem uma de suas subcategorias.']);
            }
            $parent   = $this->categories->select('parent_id')->find($parentId);
            $parentId = isset($parent['parent_id']) ? (int) $parent['parent_id'] : null;
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

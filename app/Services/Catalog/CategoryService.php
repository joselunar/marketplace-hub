<?php

namespace App\Services\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\CategoryModel;
use App\Models\ProductModel;
use App\Services\Audit\AuditService;
use App\Support\Input;

class CategoryService
{
    public function __construct(
        private readonly CategoryModel $categories = new CategoryModel(),
        private readonly ProductModel $products = new ProductModel(),
        private readonly ?AuditService $audit = null,
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

    public function update(int $id, array $input): void
    {
        $current = $this->findOrFail($id);
        $data    = $this->prepare($input, $id);

        $this->guardHierarchy($id, $data['parent_id']);

        if (! $this->categories->update($id, $data)) {
            throw new ValidationException($this->categories->errors());
        }

        $this->audit()->log('update', 'category', $id, $current, $data);
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
        $data = Input::only($input, ['parent_id', 'name', 'description']);

        $data['name']      = $data['name'] ?? '';
        $data['slug']      = Input::slug($data['name']);
        $data['parent_id'] = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $data['active']    = Input::flag($input['active'] ?? 0);

        if ($id !== null) {
            $data['id'] = $id;
        }

        return $data;
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
}

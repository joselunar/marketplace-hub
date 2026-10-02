<?php

namespace App\Models;

use CodeIgniter\Model;

class CategoryModel extends Model
{
    protected $table          = 'categories';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $allowedFields  = ['parent_id', 'name', 'slug', 'description', 'active'];
    protected $validationRules = [
        'id'          => 'permit_empty|is_natural_no_zero',
        'parent_id'   => 'permit_empty|is_natural_no_zero|is_not_unique[categories.id]',
        'name'        => 'required|min_length[2]|max_length[120]',
        'slug'        => 'required|max_length[140]|is_unique[categories.slug,id,{id}]',
        'description' => 'permit_empty|max_length[2000]',
        'active'      => 'permit_empty|in_list[0,1]',
    ];
    protected $validationMessages = [
        'slug'      => ['is_unique' => 'Já existe uma categoria com este nome/slug.'],
        'parent_id' => ['is_not_unique' => 'Categoria pai inválida.'],
    ];

    /**
     * Lista com o nome da categoria pai e a contagem de produtos.
     */
    public function withStats(): static
    {
        $this->select('categories.*, parent.name AS parent_name, COUNT(products.id) AS products_count')
            ->join('categories AS parent', 'parent.id = categories.parent_id', 'left')
            ->join('products', 'products.category_id = categories.id', 'left')
            ->groupBy('categories.id');

        return $this;
    }

    /**
     * Opções para <select>, com o caminho "Pai › Filha".
     *
     * @return array<int, string>
     */
    public function dropdownOptions(?int $excludeId = null): array
    {
        $rows = $this->select('id, parent_id, name')->orderBy('name')->findAll();
        $byId = array_column($rows, null, 'id');

        $options = [];

        foreach ($rows as $row) {
            if ($excludeId !== null && (int) $row['id'] === $excludeId) {
                continue;
            }
            $path   = [$row['name']];
            $parent = $row['parent_id'];
            $guard  = 0;

            while ($parent !== null && isset($byId[$parent]) && $guard++ < 10) {
                array_unshift($path, $byId[$parent]['name']);
                $parent = $byId[$parent]['parent_id'];
            }
            $options[(int) $row['id']] = implode(' › ', $path);
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }
}

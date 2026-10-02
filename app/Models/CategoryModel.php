<?php

namespace App\Models;

use CodeIgniter\Model;

class CategoryModel extends Model
{
    protected $table          = 'categories';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $allowedFields  = ['parent_id', 'name', 'slug', 'description', 'desired_margin_percentage', 'minimum_margin_percentage', 'active'];
    protected $validationRules = [
        'id'          => 'permit_empty|is_natural_no_zero',
        'parent_id'   => 'permit_empty|is_natural_no_zero|is_not_unique[categories.id]',
        'name'        => 'required|min_length[2]|max_length[120]',
        'slug'        => 'required|max_length[140]|is_unique[categories.slug,id,{id}]',
        'description' => 'permit_empty|max_length[2000]',
        'desired_margin_percentage' => ['label' => 'Margem desejada', 'rules' => 'permit_empty|decimal|greater_than_equal_to[0]|less_than[100]'],
        'minimum_margin_percentage' => ['label' => 'Margem mínima', 'rules' => 'permit_empty|decimal|greater_than_equal_to[0]|less_than[100]'],
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
     * A categoria e todas as suas descendentes.
     *
     * @return list<int>
     */
    public function subtreeIds(int $id): array
    {
        $rows     = $this->builder()->select('id, parent_id')->get()->getResultArray();
        $children = [];

        foreach ($rows as $row) {
            if ($row['parent_id'] !== null) {
                $children[(int) $row['parent_id']][] = (int) $row['id'];
            }
        }

        $ids   = [];
        $queue = [$id];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (in_array($current, $ids, true)) {
                continue;
            }
            $ids[] = $current;
            array_push($queue, ...($children[$current] ?? []));
        }

        return $ids;
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

<?php

namespace App\Database\Seeds;

use App\Models\CategoryModel;
use App\Support\Input;
use CodeIgniter\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * @var array<string, list<string>>
     */
    private const TREE = [
        'Eletrônicos' => ['Áudio', 'Acessórios para celular', 'Wearables'],
        'Informática' => ['Periféricos', 'Cabos e adaptadores'],
    ];

    public function run(): void
    {
        $model = new CategoryModel();

        foreach (self::TREE as $parent => $children) {
            $parentId = $this->ensure($model, $parent, null);

            foreach ($children as $child) {
                $this->ensure($model, $child, $parentId);
            }
        }
    }

    private function ensure(CategoryModel $model, string $name, ?int $parentId): int
    {
        $slug     = Input::slug($name);
        $existing = $model->where('slug', $slug)->first();

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $model->skipValidation(true)->insert(['name' => $name, 'slug' => $slug, 'parent_id' => $parentId, 'active' => 1]);

        return (int) $model->getInsertID();
    }
}

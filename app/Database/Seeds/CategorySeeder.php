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

    /**
     * Margens de demonstração (desejada, mínima). As demais categorias herdam
     * da categoria pai ou da configuração global.
     *
     * @var array<string, array{0: string|null, 1: string|null}>
     */
    private const MARGINS = [
        'Informática' => ['20.00', null],
        'Áudio'       => ['30.00', '12.00'],
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

        [$desired, $minimum] = self::MARGINS[$name] ?? [null, null];

        $model->skipValidation(true)->insert([
            'name'                      => $name,
            'slug'                      => $slug,
            'parent_id'                 => $parentId,
            'desired_margin_percentage' => $desired,
            'minimum_margin_percentage' => $minimum,
            'active'                    => 1,
        ]);

        return (int) $model->getInsertID();
    }
}

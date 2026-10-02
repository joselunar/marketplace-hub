<?php

namespace Tests\Database\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Services\Catalog\CategoryService;
use Tests\Support\DatabaseTestCase;

final class CategoryServiceTest extends DatabaseTestCase
{
    public function testPreventsCycleInHierarchy(): void
    {
        $service = new CategoryService();
        $parent  = $service->create(['name' => 'Eletrônicos', 'active' => '1']);
        $child   = $service->create(['name' => 'Áudio', 'parent_id' => (string) $parent, 'active' => '1']);

        $this->expectException(ValidationException::class);
        $service->update($parent, ['name' => 'Eletrônicos', 'parent_id' => (string) $child, 'active' => '1']);
    }

    public function testRejectsDuplicateName(): void
    {
        $service = new CategoryService();
        $service->create(['name' => 'Áudio']);

        try {
            $service->create(['name' => 'audio']);
            $this->fail('Era esperada uma ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->getErrors());
        }
    }

    public function testDoesNotDeleteCategoryWithProducts(): void
    {
        $service    = new CategoryService();
        $categoryId = $service->create(['name' => 'Periféricos']);
        $this->createProduct(['category_id' => $categoryId]);

        $this->expectException(BusinessRuleException::class);
        $service->delete($categoryId);
    }

    public function testDeletesEmptyCategory(): void
    {
        $service    = new CategoryService();
        $categoryId = $service->create(['name' => 'Temporária']);

        $service->delete($categoryId);

        $this->dontSeeInDatabase('categories', ['id' => $categoryId]);
    }
}

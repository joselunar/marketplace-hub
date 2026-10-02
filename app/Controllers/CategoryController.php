<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\CategoryModel;
use App\Services\Catalog\CategoryService;
use CodeIgniter\HTTP\RedirectResponse;

class CategoryController extends BaseController
{
    private CategoryService $service;

    public function __construct()
    {
        $this->service = new CategoryService();
    }

    public function index(): string
    {
        $filters = $this->filters(['q']);
        $model   = new CategoryModel();
        $model->withStats();

        if (isset($filters['q'])) {
            $model->like('categories.name', $filters['q']);
        }

        return view('categories/index', [
            'title'       => 'Categorias',
            'categories'  => $model->orderBy('categories.name')->paginate($this->perPage()),
            'pager'       => $model->pager,
            'filters'     => $filters,
            'breadcrumbs' => ['Catálogo' => null, 'Categorias' => null],
        ]);
    }

    public function new(): string
    {
        return $this->form(null);
    }

    public function create(): RedirectResponse
    {
        try {
            $this->service->create($this->request->getPost());
        } catch (ValidationException $e) {
            return $this->backWithErrors($e->getErrors());
        }

        return redirect()->to('/categories')->with('success', 'Categoria cadastrada.');
    }

    public function edit(int $id): string|RedirectResponse
    {
        try {
            $category = $this->service->findOrFail($id);
        } catch (BusinessRuleException $e) {
            return redirect()->to('/categories')->with('error', $e->getMessage());
        }

        return $this->form($category);
    }

    public function update(int $id): RedirectResponse
    {
        try {
            $stats = $this->service->update($id, $this->request->getPost());
        } catch (ValidationException $e) {
            return $this->backWithErrors($e->getErrors());
        } catch (BusinessRuleException $e) {
            return redirect()->to('/categories')->with('error', $e->getMessage());
        }

        $message = $stats === null
            ? 'Categoria atualizada.'
            : sprintf('Categoria atualizada. Preços e seleção recalculados para %d produto(s); %d troca(s) de fornecedor principal.', $stats['evaluated'], $stats['changed']);

        return redirect()->to('/categories')->with('success', $message);
    }

    public function delete(int $id): RedirectResponse
    {
        try {
            $this->service->delete($id);
        } catch (BusinessRuleException $e) {
            return redirect()->to('/categories')->with('error', $e->getMessage());
        }

        return redirect()->to('/categories')->with('success', 'Categoria excluída.');
    }

    private function form(?array $category): string
    {
        return view('categories/form', [
            'title'       => $category === null ? 'Nova categoria' : 'Editar categoria',
            'category'    => $category,
            'parents'     => (new CategoryModel())->dropdownOptions($category !== null ? (int) $category['id'] : null),
            'breadcrumbs' => ['Categorias' => 'categories', ($category === null ? 'Nova' : 'Editar') => null],
        ]);
    }
}

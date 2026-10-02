<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\CategoryModel;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use App\Services\Catalog\ProductService;
use App\Services\Suppliers\SupplierSelectionService;
use CodeIgniter\HTTP\RedirectResponse;

class ProductController extends BaseController
{
    private ProductService $service;

    public function __construct()
    {
        $this->service = new ProductService();
    }

    public function index(): string
    {
        $filters = $this->filters(['q', 'sku', 'ean', 'brand', 'category_id', 'status', 'supplier', 'selection', 'pricing', 'stock']);
        $model   = new ProductModel();

        $products = $model->withOfferStats()
            ->applyFilters($filters)
            ->orderBy('products.name')
            ->paginate($this->perPage());

        return view('products/index', [
            'title'       => 'Produtos',
            'products'    => $products,
            'pager'       => $model->pager,
            'filters'     => $filters,
            'categories'  => (new CategoryModel())->dropdownOptions(),
            'breadcrumbs' => ['Catálogo' => null, 'Produtos' => null],
        ]);
    }

    public function show(int $id): string|RedirectResponse
    {
        try {
            $details = $this->service->details($id);
        } catch (BusinessRuleException $e) {
            return redirect()->to('/products')->with('error', $e->getMessage());
        }

        $category = $details['product']['category_id'] !== null
            ? (new CategoryModel())->find((int) $details['product']['category_id'])
            : null;

        return view('products/show', $details + [
            'title'       => $details['product']['name'],
            'category'    => $category,
            'breadcrumbs' => ['Produtos' => 'products', $details['product']['sku'] => null],
        ]);
    }

    public function new(): string
    {
        return $this->form(null, []);
    }

    /**
     * Recalcula a seleção de fornecedor do produto e grava o resultado.
     */
    public function recalculateSelection(int $id): RedirectResponse
    {
        try {
            $result = service('supplierSelection')->select($id, SupplierSelectionService::TRIGGER_MANUAL, $this->auth()->id());
        } catch (BusinessRuleException $e) {
            return redirect()->to('/products')->with('error', $e->getMessage());
        }

        $primary = $result->primary();
        $message = $primary === null
            ? 'Seleção recalculada: nenhum fornecedor elegível.'
            : sprintf('Seleção recalculada: %s é o fornecedor principal.', $primary->supplierName);

        return redirect()->to("/products/{$id}")->with($primary === null ? 'warning' : 'success', $message);
    }

    public function create(): RedirectResponse
    {
        try {
            $id = $this->service->create($this->request->getPost(), $this->imageUrls());
        } catch (ValidationException $e) {
            return $this->backWithErrors($e->getErrors());
        }

        return redirect()->to("/products/{$id}")->with('success', 'Produto cadastrado.');
    }

    public function edit(int $id): string|RedirectResponse
    {
        try {
            $product = $this->service->findOrFail($id);
        } catch (BusinessRuleException $e) {
            return redirect()->to('/products')->with('error', $e->getMessage());
        }

        return $this->form($product, array_column((new ProductImageModel())->forProduct($id), 'url'));
    }

    public function update(int $id): RedirectResponse
    {
        try {
            $this->service->update($id, $this->request->getPost(), $this->imageUrls());
        } catch (ValidationException $e) {
            return $this->backWithErrors($e->getErrors());
        } catch (BusinessRuleException $e) {
            return redirect()->to('/products')->with('error', $e->getMessage());
        }

        return redirect()->to("/products/{$id}")->with('success', 'Produto atualizado.');
    }

    public function delete(int $id): RedirectResponse
    {
        try {
            $this->service->delete($id);
        } catch (BusinessRuleException $e) {
            return redirect()->to('/products')->with('error', $e->getMessage());
        }

        return redirect()->to('/products')->with('success', 'Produto excluído. As ofertas vinculadas ficaram sem vínculo.');
    }

    /**
     * @return list<string>
     */
    private function imageUrls(): array
    {
        $images = $this->request->getPost('images');

        return is_array($images) ? array_values(array_filter($images, 'is_string')) : [];
    }

    /**
     * @param list<string> $images
     */
    private function form(?array $product, array $images): string
    {
        $oldImages = old('images');

        return view('products/form', [
            'title'       => $product === null ? 'Novo produto' : 'Editar produto',
            'product'     => $product,
            'images'      => is_array($oldImages) ? $oldImages : $images,
            'categories'  => (new CategoryModel())->dropdownOptions(),
            'maxImages'   => ProductService::MAX_IMAGES,
            'breadcrumbs' => ['Produtos' => 'products', ($product === null ? 'Novo' : 'Editar') => null],
        ]);
    }
}

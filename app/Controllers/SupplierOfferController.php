<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\ProductModel;
use App\Models\SupplierModel;
use App\Models\SupplierOfferModel;
use App\Services\Suppliers\SupplierOfferService;
use CodeIgniter\HTTP\RedirectResponse;

class SupplierOfferController extends BaseController
{
    private SupplierOfferService $service;

    public function __construct()
    {
        $this->service = new SupplierOfferService();
    }

    public function index(): string
    {
        $filters = $this->filters(['q', 'supplier_id', 'link', 'status']);
        $model   = new SupplierOfferModel();

        $offers = $model->withRelations()
            ->applyFilters($filters)
            ->orderBy('supplier_offers.updated_at', 'DESC')
            ->paginate($this->perPage());

        return view('offers/index', [
            'title'       => 'Ofertas de fornecedores',
            'offers'      => $offers,
            'pager'       => $model->pager,
            'filters'     => $filters,
            'suppliers'   => (new SupplierModel())->dropdownOptions(),
            'breadcrumbs' => ['Catálogo' => null, 'Ofertas de fornecedores' => null],
        ]);
    }

    public function new(): string
    {
        $prefill = [
            'supplier_id' => $this->request->getGet('supplier_id'),
            'product_id'  => $this->request->getGet('product_id'),
        ];

        return $this->form(null, $prefill);
    }

    public function create(): RedirectResponse
    {
        try {
            $this->service->create($this->request->getPost());
        } catch (ValidationException $e) {
            return $this->backWithErrors($e->getErrors());
        }

        return $this->redirectAfterSave('Oferta cadastrada.');
    }

    public function edit(int $id): string|RedirectResponse
    {
        try {
            $offer = $this->service->findOrFail($id);
        } catch (BusinessRuleException $e) {
            return redirect()->to('/offers')->with('error', $e->getMessage());
        }

        return $this->form($offer, []);
    }

    public function update(int $id): RedirectResponse
    {
        try {
            $this->service->update($id, $this->request->getPost());
        } catch (ValidationException $e) {
            return $this->backWithErrors($e->getErrors());
        } catch (BusinessRuleException $e) {
            return redirect()->to('/offers')->with('error', $e->getMessage());
        }

        return $this->redirectAfterSave('Oferta atualizada.');
    }

    public function link(int $id): RedirectResponse
    {
        $productId = $this->request->getPost('product_id');
        $productId = is_numeric($productId) && (int) $productId > 0 ? (int) $productId : null;

        try {
            $this->service->link($id, $productId);
        } catch (ValidationException $e) {
            return redirect()->back()->with('error', implode(' ', $e->getErrors()));
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', $productId === null ? 'Oferta desvinculada.' : 'Oferta vinculada ao produto.');
    }

    public function delete(int $id): RedirectResponse
    {
        try {
            $this->service->delete($id);
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Oferta excluída.');
    }

    /**
     * Volta para o produto quando o formulário foi aberto a partir dele.
     */
    private function redirectAfterSave(string $message): RedirectResponse
    {
        $productId = (int) $this->request->getPost('product_id');

        if ($this->request->getPost('return_to') === 'product' && $productId > 0) {
            return redirect()->to("/products/{$productId}")->with('success', $message);
        }

        return redirect()->to('/offers')->with('success', $message);
    }

    private function form(?array $offer, array $prefill): string
    {
        $productId = old('product_id') ?? $offer['product_id'] ?? $prefill['product_id'] ?? null;
        $product   = is_numeric($productId) ? (new ProductModel())->select('id, sku, name')->find((int) $productId) : null;

        return view('offers/form', [
            'title'       => $offer === null ? 'Nova oferta' : 'Editar oferta',
            'offer'       => $offer,
            'prefill'     => $prefill,
            'product'     => $product,
            'returnTo'    => $this->request->getGet('return_to') === 'product' ? 'product' : (old('return_to') ?? ''),
            'suppliers'   => (new SupplierModel())->dropdownOptions(),
            'breadcrumbs' => ['Ofertas' => 'offers', ($offer === null ? 'Nova' : 'Editar') => null],
        ]);
    }
}

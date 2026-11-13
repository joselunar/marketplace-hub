<?php

namespace App\Controllers;

use App\Enums\SupplierIntegrationType;
use App\Enums\SupplierStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\ShippingOriginModel;
use App\Models\SupplierModel;
use App\Services\Logistics\ShippingOriginService;
use App\Services\Suppliers\Connectors\SupplierConnectorFactory;
use App\Services\Suppliers\Connectors\UnsupportedOperationException;
use App\Services\Suppliers\SupplierService;
use CodeIgniter\HTTP\RedirectResponse;

class SupplierController extends BaseController
{
    private SupplierService $service;

    public function __construct()
    {
        $this->service = new SupplierService();
    }

    public function index(): string
    {
        $filters = $this->filters(['q', 'status', 'integration_type']);
        $model   = new SupplierModel();

        $suppliers = $model->withStats()
            ->applyFilters($filters)
            ->orderBy('suppliers.priority', 'DESC')
            ->orderBy('suppliers.name')
            ->paginate($this->perPage());

        return view('suppliers/index', [
            'title'            => 'Fornecedores',
            'suppliers'        => $suppliers,
            'pager'            => $model->pager,
            'filters'          => $filters,
            'statuses'         => SupplierStatus::options(),
            'integrationTypes' => SupplierIntegrationType::options(),
            'breadcrumbs'      => ['Fornecedores' => null],
        ]);
    }

    public function show(int $id): string|RedirectResponse
    {
        try {
            $details = $this->service->details($id);
        } catch (BusinessRuleException $e) {
            return redirect()->to('/suppliers')->with('error', $e->getMessage());
        }

        $supplierId = (int) $details['supplier']['id'];

        return view('suppliers/show', $details + [
            'title'        => $details['supplier']['name'],
            'origins'      => (new ShippingOriginModel())->forSupplier($supplierId, false),
            'sourceLabels' => ShippingOriginModel::SOURCE_LABELS,
            'breadcrumbs'  => ['Fornecedores' => 'suppliers', $details['supplier']['name'] => null],
        ]);
    }

    public function storeOrigin(int $id): RedirectResponse
    {
        try {
            $this->service->findOrFail($id);
            (new ShippingOriginService())->create([
                'supplier_id'   => $id,
                'label'         => (string) $this->request->getPost('label'),
                'postal_code'   => (string) $this->request->getPost('postal_code'),
                'city'          => $this->request->getPost('city'),
                'uf'            => $this->request->getPost('uf'),
                'source_type'   => (string) $this->request->getPost('source_type'),
                'evidence_note' => $this->request->getPost('evidence_note'),
                'evidence_url'  => $this->request->getPost('evidence_url'),
                'confirmed'     => $this->request->getPost('confirmed') ? 1 : 0,
            ], $this->auth()->id());
        } catch (ValidationException $e) {
            $errors = $e->getErrors();

            return redirect()->to("/suppliers/{$id}")->with(
                'error',
                $errors !== [] ? implode(' ', $errors) : $e->getMessage(),
            );
        } catch (BusinessRuleException $e) {
            return redirect()->to("/suppliers/{$id}")->with('error', $e->getMessage());
        }

        return redirect()->to("/suppliers/{$id}")->with(
            'success',
            'CEP/CD de expedição registrado. Frete continua estimativo até cotação com esse CEP confirmado.',
        );
    }

    public function deactivateOrigin(int $id, int $originId): RedirectResponse
    {
        try {
            $this->service->findOrFail($id);
            (new ShippingOriginService())->deactivate($originId);
        } catch (BusinessRuleException $e) {
            return redirect()->to("/suppliers/{$id}")->with('error', $e->getMessage());
        }

        return redirect()->to("/suppliers/{$id}")->with('success', 'Origem de expedição desativada.');
    }

    public function new(): string
    {
        return $this->form(null);
    }

    public function create(): RedirectResponse
    {
        try {
            $id = $this->service->create($this->request->getPost());
        } catch (ValidationException $e) {
            return $this->backWithErrors($e->getErrors());
        }

        return redirect()->to("/suppliers/{$id}")->with('success', 'Fornecedor cadastrado.');
    }

    public function edit(int $id): string|RedirectResponse
    {
        try {
            $supplier = $this->service->findOrFail($id);
        } catch (BusinessRuleException $e) {
            return redirect()->to('/suppliers')->with('error', $e->getMessage());
        }

        return $this->form($supplier);
    }

    public function update(int $id): RedirectResponse
    {
        try {
            $this->service->update($id, $this->request->getPost());
        } catch (ValidationException $e) {
            return $this->backWithErrors($e->getErrors());
        } catch (BusinessRuleException $e) {
            return redirect()->to('/suppliers')->with('error', $e->getMessage());
        }

        return redirect()->to("/suppliers/{$id}")->with('success', 'Fornecedor atualizado.');
    }

    public function delete(int $id): RedirectResponse
    {
        try {
            $this->service->delete($id);
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to('/suppliers')->with('success', 'Fornecedor excluído.');
    }

    public function testConnection(int $id): RedirectResponse
    {
        try {
            $supplier  = $this->service->findOrFail($id);
            $connector = (new SupplierConnectorFactory())->make($supplier, ['file_path' => '']);
            $result    = $connector->testConnection();
        } catch (BusinessRuleException|UnsupportedOperationException|\InvalidArgumentException $e) {
            return redirect()->to("/suppliers/{$id}")->with('warning', $e->getMessage());
        }

        return redirect()->to("/suppliers/{$id}")->with($result->success ? 'success' : 'warning', $result->message);
    }

    private function form(?array $supplier): string
    {
        return view('suppliers/form', [
            'title'            => $supplier === null ? 'Novo fornecedor' : 'Editar fornecedor',
            'supplier'         => $supplier,
            'statuses'         => SupplierStatus::options(),
            'integrationTypes' => SupplierIntegrationType::options(),
            'breadcrumbs'      => ['Fornecedores' => 'suppliers', ($supplier === null ? 'Novo' : 'Editar') => null],
        ]);
    }
}

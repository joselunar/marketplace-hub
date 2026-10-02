<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Services\Suppliers\SupplierSelectionService;
use CodeIgniter\HTTP\RedirectResponse;

class SettingsController extends BaseController
{
    public function index(): string
    {
        return view('settings/index', [
            'title'       => 'Configurações',
            'groups'      => service('settings')->grouped(),
            'breadcrumbs' => ['Sistema' => null, 'Configurações' => null],
        ]);
    }

    public function update(): RedirectResponse
    {
        $input = (array) ($this->request->getPost('settings') ?? []);

        try {
            $changed = service('settings')->updateMany($input, $this->auth()->id());
        } catch (ValidationException $e) {
            return $this->backWithErrors($e->getErrors());
        }

        $affectsSelection = array_filter($changed, static fn (string $key) => preg_match('/^(pricing|stock|suppliers|ranking)\./', $key) === 1);

        if ($affectsSelection === []) {
            return redirect()->to('/settings')->with('success', 'Configurações salvas.');
        }

        $stats = service('supplierSelection')->selectAll(SupplierSelectionService::TRIGGER_SETTINGS, $this->auth()->id());

        return redirect()->to('/settings')->with('success', sprintf(
            'Configurações salvas. Seleção de fornecedores e preços recalculados para %d produto(s); %d troca(s) de fornecedor principal.',
            $stats['evaluated'],
            $stats['changed'],
        ));
    }
}

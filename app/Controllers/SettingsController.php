<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
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
            service('settings')->updateMany($input, $this->auth()->id());
        } catch (ValidationException $e) {
            return $this->backWithErrors($e->getErrors());
        }

        return redirect()->to('/settings')->with('success', 'Configurações salvas.');
    }
}

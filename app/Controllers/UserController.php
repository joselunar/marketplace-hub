<?php

namespace App\Controllers;

use App\Enums\UserRole;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\UserModel;
use App\Services\Users\UserService;
use CodeIgniter\HTTP\RedirectResponse;

class UserController extends BaseController
{
    private UserService $service;

    public function __construct()
    {
        $this->service = new UserService();
    }

    public function index(): string
    {
        $model = new UserModel();
        $users = $model->select('id, name, email, role, active, last_login_at, created_at')
            ->orderBy('name')
            ->paginate($this->perPage());

        return view('users/index', [
            'title'       => 'Usuários',
            'users'       => $users,
            'pager'       => $model->pager,
            'breadcrumbs' => ['Sistema' => null, 'Usuários' => null],
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

        return redirect()->to('/users')->with('success', 'Usuário criado.');
    }

    public function edit(int $id): string|RedirectResponse
    {
        try {
            $user = $this->service->findOrFail($id);
        } catch (BusinessRuleException $e) {
            return redirect()->to('/users')->with('error', $e->getMessage());
        }

        return $this->form($user);
    }

    public function update(int $id): RedirectResponse
    {
        try {
            $this->service->update($id, $this->request->getPost(), (int) $this->auth()->id());
        } catch (ValidationException $e) {
            return $this->backWithErrors($e->getErrors());
        } catch (BusinessRuleException $e) {
            return $this->backWithErrors([], $e->getMessage());
        }

        return redirect()->to('/users')->with('success', 'Usuário atualizado.');
    }

    private function form(?array $user): string
    {
        return view('users/form', [
            'title'       => $user === null ? 'Novo usuário' : 'Editar usuário',
            'user'        => $user,
            'roles'       => UserRole::options(),
            'isSelf'      => $user !== null && (int) $user['id'] === $this->auth()->id(),
            'breadcrumbs' => ['Usuários' => 'users', ($user === null ? 'Novo' : 'Editar') => null],
        ]);
    }
}

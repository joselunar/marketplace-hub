<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

class AuthController extends BaseController
{
    public function login(): string
    {
        return view('auth/login', ['title' => 'Entrar']);
    }

    public function attempt(): RedirectResponse
    {
        $email    = trim((string) $this->request->getPost('email'));
        $password = (string) $this->request->getPost('password');

        if ($email === '' || $password === '' || strlen($password) > 72) {
            return $this->failedLogin($email);
        }

        if ($this->auth()->attempt($email, $password) === null) {
            return $this->failedLogin($email);
        }

        $target = session('redirect_after_login');
        session()->remove('redirect_after_login');

        // Só aceita caminhos internos para evitar open redirect.
        if (! is_string($target) || ! str_starts_with($target, '/') || str_starts_with($target, '//')) {
            $target = '/dashboard';
        }

        return redirect()->to($target)->with('success', 'Bem-vindo(a)!');
    }

    public function logout(): RedirectResponse
    {
        $this->auth()->logout();

        return redirect()->to('/login')->with('success', 'Sessão encerrada.');
    }

    private function failedLogin(string $email): RedirectResponse
    {
        return redirect()->to('/login')
            ->with('_ci_old_input', ['get' => [], 'post' => ['email' => $email]])
            ->with('error', 'E-mail ou senha inválidos.');
    }
}

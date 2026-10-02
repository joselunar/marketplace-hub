<?php

namespace Tests\Feature;

use Tests\Support\FeatureTestCase;

final class AuthTest extends FeatureTestCase
{
    public function testGuestIsRedirectedToLogin(): void
    {
        $this->get('dashboard')->assertRedirectTo('/login');
    }

    public function testLoginPageIsAvailable(): void
    {
        $result = $this->get('login');

        $result->assertOK();
        $result->assertSee('Acesso administrativo');
    }

    public function testLoginWithValidCredentialsStartsSession(): void
    {
        $user = $this->createUser('admin', ['email' => 'admin@teste.local', 'password' => 'senha-forte-1']);

        $result = $this->post('login', ['email' => 'ADMIN@teste.local', 'password' => 'senha-forte-1']);

        $result->assertRedirectTo('/dashboard');
        $result->assertSessionHas('user_id', $user['id']);
        $this->seeInDatabase('audit_logs', ['action' => 'login', 'user_id' => $user['id']]);
        $this->dontSeeInDatabase('users', ['id' => $user['id'], 'last_login_at' => null]);
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $this->createUser('admin', ['email' => 'admin@teste.local', 'password' => 'senha-forte-1']);

        $result = $this->post('login', ['email' => 'admin@teste.local', 'password' => 'errada']);

        $result->assertRedirectTo('/login');
        $result->assertSessionMissing('user_id');
        $result->assertSessionHas('error', 'E-mail ou senha inválidos.');
    }

    public function testInactiveUserCannotLogin(): void
    {
        $this->createUser('operator', ['email' => 'inativo@teste.local', 'password' => 'senha-forte-1', 'active' => 0]);

        $this->post('login', ['email' => 'inativo@teste.local', 'password' => 'senha-forte-1'])
            ->assertSessionMissing('user_id');
    }

    public function testLoginIsRateLimited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('login', ['email' => 'x@teste.local', 'password' => 'errada']);
        }

        $this->post('login', ['email' => 'x@teste.local', 'password' => 'errada'])
            ->assertSessionHas('error', 'Muitas tentativas de login. Aguarde um minuto e tente novamente.');
    }

    public function testDeactivatedUserLosesAccessImmediately(): void
    {
        $user = $this->createUser('operator');
        $this->db->table('users')->where('id', $user['id'])->update(['active' => 0]);

        $this->actingAs($user)->get('dashboard')->assertRedirectTo('/login');
    }

    public function testLogoutEndsSession(): void
    {
        $user = $this->createUser('operator');

        $result = $this->actingAs($user)->post('logout');

        $result->assertRedirectTo('/login');
        $result->assertSessionMissing('user_id');
    }
}

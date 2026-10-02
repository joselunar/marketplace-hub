<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\UserModel;
use App\Services\Audit\AuditService;
use CodeIgniter\Session\Session;

/**
 * Autenticação de usuários internos baseada em sessão.
 */
class AuthService
{
    private ?array $cachedUser = null;
    private bool $loaded       = false;

    public function __construct(
        private readonly UserModel $users = new UserModel(),
        private readonly ?AuditService $audit = null,
        private ?Session $session = null,
    ) {
    }

    /**
     * Valida credenciais e abre a sessão. Retorna o usuário (sem hash) ou null.
     */
    public function attempt(string $email, string $password): ?array
    {
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            // Mantém o tempo de resposta parecido para não revelar e-mails cadastrados.
            password_verify($password, self::dummyHash());

            return null;
        }

        if (! password_verify($password, $user['password_hash']) || (int) $user['active'] !== 1) {
            return null;
        }

        $changes = ['last_login_at' => date('Y-m-d H:i:s')];

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $changes['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $this->users->skipValidation()->update($user['id'], $changes);

        $session = $this->session();
        $session->regenerate(true);
        $session->set([
            'user_id'   => (int) $user['id'],
            'user_role' => $user['role'],
        ]);

        $this->cachedUser = UserModel::withoutSecrets(array_merge($user, $changes));
        $this->loaded     = true;

        $this->audit()->log('login', 'user', (int) $user['id'], userId: (int) $user['id']);

        return $this->cachedUser;
    }

    public function logout(): void
    {
        $userId = $this->id();

        if ($userId !== null) {
            $this->audit()->log('logout', 'user', $userId, userId: $userId);
        }

        $session = $this->session();
        $session->remove(['user_id', 'user_role']);
        $session->regenerate(true);

        $this->cachedUser = null;
        $this->loaded     = true;
    }

    /**
     * Usuário autenticado, recarregado do banco uma vez por requisição
     * para refletir desativações e trocas de papel imediatamente.
     */
    public function user(): ?array
    {
        if ($this->loaded) {
            return $this->cachedUser;
        }
        $this->loaded = true;

        $userId = $this->session()->get('user_id');

        if ($userId === null) {
            return null;
        }

        $user = $this->users->find((int) $userId);

        if ($user === null || (int) $user['active'] !== 1) {
            $this->session()->remove(['user_id', 'user_role']);

            return null;
        }

        $this->cachedUser = UserModel::withoutSecrets($user);

        return $this->cachedUser;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): ?int
    {
        $user = $this->user();

        return $user !== null ? (int) $user['id'] : null;
    }

    public function hasRole(UserRole|string ...$roles): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        foreach ($roles as $role) {
            $value = $role instanceof UserRole ? $role->value : $role;

            if ($user['role'] === $value) {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(UserRole::Admin);
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    }

    private function session(): Session
    {
        return $this->session ??= service('session');
    }

    private function audit(): AuditService
    {
        return $this->audit ?? service('audit');
    }
}

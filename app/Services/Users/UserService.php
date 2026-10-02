<?php

namespace App\Services\Users;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\UserModel;
use App\Services\Audit\AuditService;
use App\Support\Input;

class UserService
{
    public const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserModel $users = new UserModel(),
        private readonly ?AuditService $audit = null,
    ) {
    }

    public function create(array $input): int
    {
        $data     = $this->prepare($input);
        $password = (string) ($input['password'] ?? '');

        $this->validatePassword($password, (string) ($input['password_confirm'] ?? ''), required: true);
        $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);

        if (! $this->users->insert($data)) {
            throw new ValidationException($this->users->errors());
        }
        $id = (int) $this->users->getInsertID();

        $this->audit()->log('create', 'user', $id, null, $data);

        return $id;
    }

    public function update(int $id, array $input, int $actingUserId): void
    {
        $current = $this->findOrFail($id);
        $data    = $this->prepare($input, $id);

        if ($id === $actingUserId) {
            if ($data['active'] !== 1) {
                throw new BusinessRuleException('Você não pode desativar o próprio usuário.');
            }

            if ($data['role'] !== $current['role']) {
                throw new BusinessRuleException('Você não pode alterar o próprio papel.');
            }
        }

        $password = (string) ($input['password'] ?? '');

        if ($password !== '') {
            $this->validatePassword($password, (string) ($input['password_confirm'] ?? ''), required: true);
            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        if (! $this->users->update($id, $data)) {
            throw new ValidationException($this->users->errors());
        }

        $this->audit()->log('update', 'user', $id, $current, $data);
    }

    public function findOrFail(int $id): array
    {
        $user = $this->users->find($id);

        if ($user === null) {
            throw new BusinessRuleException('Usuário não encontrado.');
        }

        return UserModel::withoutSecrets($user);
    }

    private function prepare(array $input, ?int $id = null): array
    {
        $data = Input::only($input, ['name', 'email', 'role']);

        $data['name']   = $data['name'] ?? '';
        $data['email']  = mb_strtolower($data['email'] ?? '');
        $data['role']   = $data['role'] ?? '';
        $data['active'] = Input::flag($input['active'] ?? 0);

        if ($id !== null) {
            $data['id'] = $id;
        }

        return $data;
    }

    private function validatePassword(string $password, string $confirmation, bool $required): void
    {
        if (! $required && $password === '') {
            return;
        }

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new ValidationException(['password' => 'A senha deve ter pelo menos ' . self::MIN_PASSWORD_LENGTH . ' caracteres.']);
        }

        if (strlen($password) > 72) {
            throw new ValidationException(['password' => 'A senha deve ter no máximo 72 caracteres.']);
        }

        if (! hash_equals($password, $confirmation)) {
            throw new ValidationException(['password_confirm' => 'A confirmação de senha não confere.']);
        }
    }

    private function audit(): AuditService
    {
        return $this->audit ?? service('audit');
    }
}

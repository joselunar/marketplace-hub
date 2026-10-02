<?php

namespace App\Models;

use App\Enums\UserRole;
use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table          = 'users';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $allowedFields  = ['name', 'email', 'password_hash', 'role', 'active', 'last_login_at'];
    protected $validationMessages = [
        'email' => ['is_unique' => 'Já existe um usuário com este e-mail.'],
    ];

    protected function initialize(): void
    {
        $this->validationRules = [
            'id'     => 'permit_empty|is_natural_no_zero',
            'name'   => 'required|min_length[3]|max_length[120]',
            'email'  => 'required|valid_email|max_length[190]|is_unique[users.email,id,{id}]',
            'role'   => 'required|' . UserRole::inListRule(),
            'active' => 'permit_empty|in_list[0,1]',
        ];
    }

    public function findByEmail(string $email): ?array
    {
        return $this->where('email', mb_strtolower(trim($email)))->first();
    }

    /**
     * Remove o hash da senha antes de devolver o registro para views/API.
     */
    public static function withoutSecrets(array $user): array
    {
        unset($user['password_hash']);

        return $user;
    }
}

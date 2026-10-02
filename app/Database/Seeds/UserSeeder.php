<?php

namespace App\Database\Seeds;

use App\Enums\UserRole;
use App\Models\UserModel;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Usuários de desenvolvimento. E-mails e senhas vêm do .env
 * (SEED_ADMIN_* / SEED_OPERATOR_*). Sem senha definida, gera uma aleatória.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $this->createUser('Administrador', 'SEED_ADMIN', 'admin@marketplacehub.local', UserRole::Admin);
        $this->createUser('Operador', 'SEED_OPERATOR', 'operador@marketplacehub.local', UserRole::Operator);
    }

    private function createUser(string $name, string $envPrefix, string $defaultEmail, UserRole $role): void
    {
        $model = new UserModel();
        $email = mb_strtolower((string) (env($envPrefix . '_EMAIL') ?: $defaultEmail));

        if ($model->findByEmail($email) !== null) {
            $this->say("Usuário {$email} já existe; mantido.");

            return;
        }

        $password  = (string) env($envPrefix . '_PASSWORD');
        $generated = $password === '';

        if ($generated) {
            $password = bin2hex(random_bytes(6));
        }

        $model->skipValidation(true)->insert([
            'name'          => $name,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role->value,
            'active'        => 1,
        ]);

        $this->say("Usuário {$role->label()} criado: {$email}" . ($generated ? " (senha gerada: {$password})" : ' (senha definida no .env)'));
    }

    private function say(string $message): void
    {
        if (is_cli() && ENVIRONMENT !== 'testing') {
            CLI::write($message, 'yellow');
        }
    }
}

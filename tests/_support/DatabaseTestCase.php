<?php

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;

/**
 * Base para testes que usam o banco de testes (database.tests.* no .env).
 *
 * As migrations rodam do zero uma vez por execução do PHPUnit; antes de cada
 * teste todas as tabelas são esvaziadas, o que garante isolamento sem o custo
 * de recriar o schema a cada caso.
 */
abstract class DatabaseTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait {
        setUpMigrate as private migrateFromScratch;
    }

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = 'App';

    /**
     * O trait do framework zera o próprio controle ao fim de cada classe
     * (#[AfterClass]); este fica valendo para a execução inteira.
     */
    private static bool $schemaReady = false;

    protected function setUpMigrate(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $this->migrateFromScratch();
        self::$schemaReady = true;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearTables();

        foreach (['auth', 'audit', 'settings', 'pricing', 'supplierSelection', 'mercadoLivre', 'mercadoLivreTransport', 'mercadoLivreProducts'] as $service) {
            Services::resetSingle($service);
        }
    }

    /**
     * DELETE em vez de TRUNCATE: no InnoDB o TRUNCATE recria a tabela,
     * o que é bem mais lento para as poucas linhas de cada teste.
     */
    private function clearTables(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->db->listTables() as $table) {
            if ($table !== 'migrations') {
                $this->db->table($table)->emptyTable();
            }
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function createSupplier(array $overrides = []): int
    {
        static $sequence = 0;
        $sequence++;

        $this->db->table('suppliers')->insert($overrides + [
            'name'             => 'Fornecedor ' . $sequence,
            'integration_type' => 'manual',
            'status'           => 'active',
            'priority'         => 5,
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    protected function createProduct(array $overrides = []): int
    {
        static $sequence = 0;
        $sequence++;

        $this->db->table('products')->insert($overrides + [
            'sku'        => 'SKU-' . $sequence,
            'name'       => 'Produto ' . $sequence,
            'slug'       => 'produto-' . $sequence . '-' . uniqid(),
            'active'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    protected function createUser(string $role = 'admin', array $overrides = []): array
    {
        $password = $overrides['password'] ?? 'senha-segura-123';
        unset($overrides['password']);

        $data = $overrides + [
            'name'          => ucfirst($role) . ' Teste',
            'email'         => $role . uniqid() . '@teste.local',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role,
            'active'        => 1,
            'created_at'    => date('Y-m-d H:i:s'),
        ];
        $this->db->table('users')->insert($data);
        $data['id']       = (int) $this->db->insertID();
        $data['password'] = $password;

        return $data;
    }
}

<?php

namespace Tests\Feature;

use App\Database\Seeds\DevelopmentSeeder;
use Tests\Support\FeatureTestCase;

final class AccessControlTest extends FeatureTestCase
{
    public function testAdminCanOpenAllMainScreens(): void
    {
        $this->seed(DevelopmentSeeder::class);
        $admin = $this->createUser('admin');

        $product  = $this->db->table('products')->select('id')->get()->getRow()->id;
        $supplier = $this->db->table('suppliers')->select('id')->get()->getRow()->id;
        $offer    = $this->db->table('supplier_offers')->select('id')->get()->getRow()->id;
        $category = $this->db->table('categories')->select('id')->get()->getRow()->id;

        $pages = [
            'dashboard', 'products', 'products/new', "products/{$product}", "products/{$product}/edit",
            'products?supplier=without&stock=out&status=active',
            'categories', 'categories/new', "categories/{$category}/edit",
            'suppliers', 'suppliers/new', "suppliers/{$supplier}", "suppliers/{$supplier}/edit",
            'offers', 'offers/new', "offers/{$offer}/edit", 'offers?link=unlinked',
            'imports', 'imports/new', 'users', 'users/new', 'settings', 'logs', 'logs?tab=sync',
            'marketplaces/mercadolivre', 'marketplaces/mercadolivre/categories', 'marketplaces/mercadolivre/listings',
            'marketplaces/mercadolivre/notifications', 'orders', 'orders?fulfillment=awaiting_supplier&period=7d',
            'sync', 'sync?status=failed&type=meli_stock&q=MLB',
        ];

        foreach ($pages as $page) {
            $this->actingAs($admin)->get($page)->assertOK();
        }
    }

    public function testOperatorCannotManageUsersOrSettings(): void
    {
        $operator = $this->createUser('operator');

        foreach (['users', 'settings', 'logs'] as $page) {
            $this->actingAs($operator)->get($page)->assertStatus(403);
        }

        $this->actingAs($operator)->post('settings', ['settings' => []])->assertStatus(403);
    }

    public function testOperatorCannotDeleteRecords(): void
    {
        $operator   = $this->createUser('operator');
        $supplierId = $this->createSupplier();

        $this->actingAs($operator)->post("suppliers/{$supplierId}/delete")->assertStatus(403);
        $this->seeInDatabase('suppliers', ['id' => $supplierId]);
    }

    public function testOperatorCanManageCatalog(): void
    {
        $operator = $this->createUser('operator');

        $this->actingAs($operator)->post('suppliers', [
            'name'             => 'Fornecedor Novo',
            'integration_type' => 'manual',
            'status'           => 'active',
            'priority'         => '5',
        ])->assertRedirect();

        $this->seeInDatabase('suppliers', ['name' => 'Fornecedor Novo']);
        $this->seeInDatabase('audit_logs', ['entity_type' => 'supplier', 'action' => 'create', 'user_id' => $operator['id']]);
    }

    public function testInvalidFormReturnsErrors(): void
    {
        $admin = $this->createUser('admin');

        $result = $this->actingAs($admin)->post('products', ['sku' => '', 'name' => '']);

        $result->assertRedirect();
        $result->assertSessionHas('errors');
        $errors = session('errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('sku', $errors);
        $this->assertArrayHasKey('name', $errors);
    }

    public function testAdminCannotDeactivateThemselves(): void
    {
        $admin = $this->createUser('admin');

        $this->actingAs($admin)->post("users/{$admin['id']}", [
            'name'   => $admin['name'],
            'email'  => $admin['email'],
            'role'   => 'admin',
            'active' => '0',
        ]);

        $this->seeInDatabase('users', ['id' => $admin['id'], 'active' => 1]);
    }

    public function testUserPasswordIsNeverStoredInSessionOrAudit(): void
    {
        $admin = $this->createUser('admin');

        $this->actingAs($admin)->post('users', [
            'name'             => 'Novo Operador',
            'email'            => 'novo@teste.local',
            'role'             => 'operator',
            'active'           => '1',
            'password'         => 'senha-super-secreta',
            'password_confirm' => 'senha-super-secreta',
        ])->assertRedirectTo('/users');

        $audit = $this->db->table('audit_logs')->where('entity_type', 'user')->where('action', 'create')->get()->getRowArray();
        $this->assertStringNotContainsString('senha-super-secreta', (string) $audit['new_values']);
        $this->assertStringContainsString('[REDACTED]', (string) $audit['new_values']);

        $user = $this->db->table('users')->where('email', 'novo@teste.local')->get()->getRowArray();
        $this->assertTrue(password_verify('senha-super-secreta', $user['password_hash']));
    }
}

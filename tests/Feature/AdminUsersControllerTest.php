<?php

namespace Tests\Feature;

use App\Models\StaffRole;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUsersControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): void
    {
        $admin = Usuario::factory()->create();
        Sanctum::actingAs($admin);
    }

    public function test_store_assigns_staff_role(): void
    {
        $this->actingAsAdmin();

        $role = StaffRole::factory()->admin()->create([
            'modules' => ['dashboard', 'reportes'],
        ]);

        $response = $this->postJson('/api/admin/users', [
            'nombre' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret123',
            'role' => 'admin',
            'staff_role_id' => $role->id,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('usuarios', [
            'email' => 'jane@example.com',
            'staff_role_id' => $role->id,
        ]);
    }

    public function test_store_with_custom_modules(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/users', [
            'nombre' => 'Cashier',
            'email' => 'cashier@example.com',
            'password' => 'secret123',
            'role' => 'cashier',
            'modules' => ['caja', 'cancelaciones'],
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('usuarios', [
            'email' => 'cashier@example.com',
            'staff_role_id' => null,
        ]);

        $this->assertEquals(
            ['caja', 'cancelaciones'],
            Usuario::where('email', 'cashier@example.com')->first()->modules
        );
    }

    public function test_invalid_modules_fallback_to_defaults(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/users', [
            'nombre' => 'Admin Default',
            'email' => 'default@example.com',
            'password' => 'secret123',
            'role' => 'admin',
            'modules' => ['foo', 'bar'],
        ]);

        $response->assertCreated();

        $created = Usuario::where('email', 'default@example.com')->first();
        $this->assertNotEmpty($created->modules);
        $this->assertContains('dashboard', $created->modules);
    }

    public function test_staff_role_mismatch_returns_validation_error(): void
    {
        $this->actingAsAdmin();
        $staffRole = StaffRole::factory()->staff()->create();

        $response = $this->postJson('/api/admin/users', [
            'nombre' => 'Mismatch Admin',
            'email' => 'mismatch@example.com',
            'password' => 'secret123',
            'role' => 'admin',
            'staff_role_id' => $staffRole->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_update_rejects_modules_when_missing_permission(): void
    {
        $this->actingAsAdmin();
        $user = Usuario::factory()->cashier()->create([
            'modules' => ['caja'],
        ]);

        $response = $this->patchJson("/api/admin/users/{$user->id}", [
            'modules' => ['invalid-module'],
        ]);

        $response->assertOk();
        $this->assertContains('caja', $user->fresh()->modules);
    }
}

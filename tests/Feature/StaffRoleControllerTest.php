<?php

namespace Tests\Feature;

use App\Models\StaffRole;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffRoleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): Usuario
    {
        $admin = Usuario::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_create_role_and_set_default(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/staff-roles', [
            'name' => 'Administrador completo',
            'slug' => 'admin-full',
            'base_role' => 'admin',
            'modules' => ['dashboard', 'caja', 'reportes'],
            'is_default' => true,
        ]);

        $response->assertCreated()
            ->assertJsonFragment(['name' => 'Administrador completo']);

        $this->assertDatabaseHas('staff_roles', [
            'slug' => 'admin-full',
            'is_default' => true,
        ]);
    }

    public function test_setting_new_default_unsets_previous_one(): void
    {
        $this->actingAsAdmin();

        $first = StaffRole::factory()->admin()->create([
            'is_default' => true,
            'modules' => ['dashboard'],
        ]);

        $second = $this->postJson('/api/admin/staff-roles', [
            'name' => 'Supervisor',
            'slug' => 'supervisor',
            'base_role' => 'admin',
            'modules' => ['dashboard', 'reportes'],
            'is_default' => true,
        ])->json();

        $this->assertFalse($first->fresh()->is_default, 'previous default should be unset');
        $this->assertTrue(StaffRole::find($second['id'])->is_default);
    }

    public function test_index_returns_roles_grouped(): void
    {
        $this->actingAsAdmin();
        StaffRole::factory()->admin()->count(2)->create();
        StaffRole::factory()->staff()->count(1)->create();

        $response = $this->getJson('/api/admin/staff-roles');
        $response->assertOk()->assertJsonCount(3);
    }

    public function test_cannot_delete_role_in_use(): void
    {
        $this->actingAsAdmin();

        $role = StaffRole::factory()->staff()->create();
        $user = Usuario::factory()->cashier()->create([
            'staff_role_id' => $role->id,
        ]);

        $response = $this->deleteJson("/api/admin/staff-roles/{$role->id}");
        $response->assertStatus(409);

        $this->assertDatabaseHas('staff_roles', ['id' => $role->id]);
        $this->assertDatabaseHas('usuarios', ['id' => $user->id, 'staff_role_id' => $role->id]);
    }
}

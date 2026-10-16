<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RoleSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserActionsTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    public function test_superadmin_can_deactivate_activate_restore_and_reset_password(): void
    {
        $admin = $this->userWithRole(RoleSlug::SUPERADMIN);
        $target = $this->userWithRole(RoleSlug::FACULTY);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$target->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('users', ['id' => $target->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users?per_page=200')
            ->assertOk()
            ->assertJsonFragment(['email' => $target->email, 'status' => 'Inactive']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$target->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'Active');

        $this->assertNull($target->fresh()->deleted_at);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$target->id}/deactivate")
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$target->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'Active');

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$target->id}/reset-password", [
                'password' => 'Welcome123!',
            ])
            ->assertOk()
            ->assertJsonPath('data.temporary_password', 'Welcome123!');

        $this->assertTrue(Hash::check('Welcome123!', $target->fresh()->password));
        $this->assertSame('Welcome123!', $response->json('data.temporary_password'));
    }

    public function test_superadmin_cannot_deactivate_self(): void
    {
        $admin = $this->userWithRole(RoleSlug::SUPERADMIN);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$admin->id}/deactivate")
            ->assertStatus(403);
    }
}

<?php

namespace Tests\Feature\Lite;

use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiteAdminPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_auth(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_dashboard_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'dashboard.view');

        $response = $this->actingAs($actor)->get('/dashboard');

        $response->assertOk();
    }

    public function test_clients_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'clients.manage');

        $response = $this->actingAs($actor)->get('/admin/clients');

        $response->assertOk();
    }

    public function test_client_connections_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'integrations.manage');

        $client = Client::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'active' => true,
        ]);

        $response = $this->actingAs($actor)->get("/admin/clients/{$client->id}/connections");

        $response->assertOk();
    }

    public function test_client_templates_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'integrations.manage');

        $client = Client::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'active' => true,
        ]);

        $response = $this->actingAs($actor)->get("/admin/clients/{$client->id}/templates");

        $response->assertOk();
    }

    public function test_client_flows_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'integrations.manage');

        $client = Client::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'active' => true,
        ]);

        $response = $this->actingAs($actor)->get("/admin/clients/{$client->id}/flows");

        $response->assertOk();
    }

    public function test_client_owners_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'integrations.manage');
        $client = Client::query()->create(['name' => 'Acme', 'slug' => 'acme', 'active' => true]);

        $this->actingAs($actor)->get("/admin/clients/{$client->id}/owners")->assertOk();
    }

    public function test_legacy_rule_pages_redirect_to_flows(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'integrations.manage');
        $client = Client::query()->create(['name' => 'Acme', 'slug' => 'acme', 'active' => true]);

        $this->actingAs($actor)->get("/admin/clients/{$client->id}/rules")
            ->assertRedirect("/admin/clients/{$client->id}/flows");
        $this->actingAs($actor)->get("/admin/clients/{$client->id}/owner-rules")
            ->assertRedirect("/admin/clients/{$client->id}/flows");
    }

    public function test_records_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'records.view');

        $response = $this->actingAs($actor)->get('/admin/records');

        $response->assertOk();
    }

    public function test_users_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'users.manage');

        $response = $this->actingAs($actor)->get('/admin/users');

        $response->assertOk();
    }

    public function test_roles_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'roles.manage');

        $response = $this->actingAs($actor)->get('/admin/roles');

        $response->assertOk();
    }

    private function grantPermission(User $user, string $permissionSlug): void
    {
        $role = Role::query()->create([
            'name' => 'Lite Role '.$permissionSlug,
            'slug' => 'lite-role-'.str_replace('.', '-', $permissionSlug).'-'.$user->id,
            'description' => 'Temporary Lite role for tests',
        ]);

        $permission = Permission::query()->where('slug', $permissionSlug)->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);
    }
}

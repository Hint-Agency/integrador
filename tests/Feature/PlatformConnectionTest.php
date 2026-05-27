<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_hubspot_connection_reports_missing_token(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config()->set('hubspot.access_token', null);

        $user = User::factory()->create([
            'email' => 'api-user@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->grantPermission($user, 'platforms.manage');

        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $response = $this->postJson('/api/platforms/'.$platform->id.'/test-connection', [], [
            'Authorization' => 'Basic '.base64_encode('api-user@example.com:password'),
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'data' => [
                'configured' => false,
            ],
        ]);
    }

    public function test_hubspot_connection_can_validate_token_with_ping(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        Http::fake([
            'https://api.hubapi.test/integrations/v1/me' => Http::response([
                'portalId' => 999,
            ], 200),
        ]);

        $user = User::factory()->create([
            'email' => 'api-user2@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->grantPermission($user, 'platforms.manage');

        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $response = $this->postJson('/api/platforms/'.$platform->id.'/test-connection', [], [
            'Authorization' => 'Basic '.base64_encode('api-user2@example.com:password'),
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'configured' => true,
            ],
        ]);
    }

    public function test_odoo_connection_reports_missing_credentials(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config()->set('odoo.url', null);
        config()->set('odoo.database', null);
        config()->set('odoo.username', null);
        config()->set('odoo.password', null);

        $user = User::factory()->create([
            'email' => 'api-user3@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->grantPermission($user, 'platforms.manage');

        $platform = Platform::query()->create([
            'name' => 'Odoo',
            'slug' => 'odoo',
            'type' => 'odoo',
            'active' => true,
        ]);

        $response = $this->postJson('/api/platforms/'.$platform->id.'/test-connection', [], [
            'Authorization' => 'Basic '.base64_encode('api-user3@example.com:password'),
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'data' => [
                'configured' => false,
            ],
        ]);
    }

    public function test_odoo_connection_uses_platform_credentials_and_settings(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config()->set('odoo.url', null);
        config()->set('odoo.database', null);
        config()->set('odoo.username', null);
        config()->set('odoo.password', null);

        Http::fake([
            'https://odoo-directo.test/jsonrpc' => Http::response([
                'result' => 42,
            ], 200),
        ]);

        $user = User::factory()->create([
            'email' => 'api-user-odoo-platform@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->grantPermission($user, 'platforms.manage');

        $platform = Platform::query()->create([
            'name' => 'Odoo directoGroup',
            'slug' => 'odoo-directogroup',
            'type' => 'odoo',
            'active' => true,
            'credentials' => [
                'database' => 'directo_odoo',
                'username' => 'odoo_user',
                'password' => 'odoo_pass',
            ],
            'settings' => [
                'url' => 'https://odoo-directo.test',
                'odoo' => [
                    'adapter' => 'json_rpc',
                ],
            ],
        ]);

        $response = $this->postJson('/api/platforms/'.$platform->id.'/test-connection', [], [
            'Authorization' => 'Basic '.base64_encode('api-user-odoo-platform@example.com:password'),
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'configured' => true,
                'uid' => 42,
                'adapter' => 'json_rpc',
            ],
        ]);
    }

    public function test_netsuite_connection_reports_missing_credentials(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config()->set('netsuite.account', null);
        config()->set('netsuite.consumer_key', null);
        config()->set('netsuite.consumer_secret', null);
        config()->set('netsuite.token_id', null);
        config()->set('netsuite.token_secret', null);

        $user = User::factory()->create([
            'email' => 'api-user4@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->grantPermission($user, 'platforms.manage');

        $platform = Platform::query()->create([
            'name' => 'NetSuite',
            'slug' => 'netsuite',
            'type' => 'netsuite',
            'active' => true,
        ]);

        $response = $this->postJson('/api/platforms/'.$platform->id.'/test-connection', [], [
            'Authorization' => 'Basic '.base64_encode('api-user4@example.com:password'),
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'data' => [
                'configured' => false,
            ],
        ]);
    }

    private function grantPermission(User $user, string $permissionSlug): void
    {
        $role = Role::query()->create([
            'name' => 'API Role '.$permissionSlug,
            'slug' => 'api-role-'.str_replace('.', '-', $permissionSlug).'-'.$user->id,
            'description' => 'Temporary API role for tests',
        ]);

        $permission = Permission::query()->where('slug', $permissionSlug)->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);
    }
}

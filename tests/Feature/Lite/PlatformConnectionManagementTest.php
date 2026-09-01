<?php

namespace Tests\Feature\Lite;

use App\Models\Client;
use App\Models\Permission;
use App\Models\PlatformConnection;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformConnectionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_hubspot_connection_validates_and_stores_app_id_metadata(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $actor = User::factory()->create();
        $this->grantPermission($actor, 'integrations.manage');
        $client = Client::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'active' => true,
        ]);

        Http::fake([
            'https://api.hubapi.com/oauth/v2/private-apps/get/access-token-info' => Http::response([
                'appId' => 123456,
                'hubId' => 789,
                'scopes' => ['crm.objects.contacts.read'],
            ]),
        ]);

        $response = $this->actingAs($actor)->post("/admin/clients/{$client->id}/connections", [
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'platform_type' => 'hubspot',
            'base_url' => 'https://api.hubapi.com',
            'signature_header' => 'x-hubspot-signature',
            'webhook_secret' => 'webhook-secret',
            'active' => true,
            'credentials' => ['access_token' => 'pat-na1-private-token'],
            'settings' => [
                'app_id' => 123456,
                'contact_properties' => ['firstname'],
                'timeout_seconds' => 20,
            ],
        ]);

        $response->assertRedirect("/admin/clients/{$client->id}/connections");

        $connection = PlatformConnection::query()->where('client_id', $client->id)->firstOrFail();
        $this->assertSame(123456, $connection->settings['app_id']);
        $this->assertSame(123456, $connection->settings['credential_validation']['app_id']);
        $this->assertSame(789, $connection->settings['credential_validation']['hub_id']);
        $this->assertSame('private_app', $connection->settings['credential_validation']['credential_type']);
        $this->assertNotEmpty($connection->settings['credential_validation']['validated_at']);
        $this->assertSame('pat-na1-private-token', $connection->credentials['access_token']);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request['tokenKey'] === 'pat-na1-private-token');
    }

    public function test_hubspot_connection_rejects_app_id_that_does_not_belong_to_token(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $actor = User::factory()->create();
        $this->grantPermission($actor, 'integrations.manage');
        $client = Client::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'active' => true,
        ]);

        Http::fake([
            'https://api.hubapi.com/oauth/v1/access-tokens/*' => Http::response([
                'app_id' => 111111,
                'hub_id' => 789,
            ]),
        ]);

        $response = $this->actingAs($actor)->post("/admin/clients/{$client->id}/connections", [
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'platform_type' => 'hubspot',
            'base_url' => 'https://api.hubapi.com',
            'active' => true,
            'credentials' => ['access_token' => 'oauth-access-token'],
            'settings' => [
                'app_id' => 222222,
                'contact_properties' => ['firstname'],
            ],
        ]);

        $response->assertSessionHasErrors('settings.app_id');
        $this->assertDatabaseCount('platform_connections', 0);
    }

    public function test_existing_hubspot_connection_can_validate_app_id_without_resending_token(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $actor = User::factory()->create();
        $this->grantPermission($actor, 'integrations.manage');
        $client = Client::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'active' => true,
        ]);
        $connection = PlatformConnection::query()->create([
            'client_id' => $client->id,
            'platform_type' => 'hubspot',
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'base_url' => 'https://api.hubapi.com',
            'signature_header' => 'x-hubspot-signature',
            'webhook_secret' => 'webhook-secret',
            'credentials' => ['access_token' => 'pat-na1-existing-token'],
            'settings' => ['contact_properties' => ['firstname']],
            'active' => true,
        ]);

        Http::fake([
            'https://api.hubapi.com/oauth/v2/private-apps/get/access-token-info' => Http::response([
                'appId' => 51360949,
                'hubId' => 51111527,
                'scopes' => ['crm.objects.contacts.read'],
            ]),
        ]);

        $response = $this->actingAs($actor)->put("/admin/clients/{$client->id}/connections/{$connection->id}", [
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'platform_type' => 'hubspot',
            'base_url' => 'https://api.hubapi.com',
            'signature_header' => 'x-hubspot-signature',
            'webhook_secret' => null,
            'active' => true,
            'credentials' => [],
            'settings' => [
                'app_id' => 51360949,
                'contact_properties' => ['firstname'],
                'timeout_seconds' => 20,
            ],
        ]);

        $response->assertRedirect("/admin/clients/{$client->id}/connections");

        $connection->refresh();
        $this->assertSame(51360949, $connection->settings['app_id']);
        $this->assertSame(51360949, $connection->settings['credential_validation']['app_id']);
        $this->assertSame('pat-na1-existing-token', $connection->credentials['access_token']);
    }

    public function test_treble_connection_update_persists_base_url_and_send_path(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $actor = User::factory()->create();
        $this->grantPermission($actor, 'integrations.manage');

        $client = Client::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'active' => true,
        ]);

        $connection = PlatformConnection::query()->create([
            'client_id' => $client->id,
            'platform_type' => 'treble',
            'name' => 'Treble',
            'slug' => 'treble',
            'base_url' => 'https://main.treble.ai',
            'signature_header' => 'X-Treble-Webhook-Secret',
            'webhook_secret' => PlatformConnection::generateWebhookSecret(),
            'credentials' => ['api_key' => 'existing-key'],
            'settings' => [
                'send_path' => '/deployment/api/poll/{poll_id}',
                'auth_mode' => 'bearer_api_key',
                'country_code_default' => '52',
            ],
            'active' => true,
        ]);

        $response = $this->actingAs($actor)->put("/admin/clients/{$client->id}/connections/{$connection->id}", [
            'name' => 'Treble',
            'slug' => 'treble',
            'platform_type' => 'treble',
            'base_url' => 'https://hooks.treble.ai',
            'signature_header' => 'X-Treble-Webhook-Secret',
            'active' => true,
            'credentials' => [],
            'settings' => [
                'send_path' => '/deployment/api/poll/custom/{poll_id}',
                'http_method' => 'POST',
                'auth_mode' => 'bearer_api_key',
                'api_key_header' => 'X-API-Key',
                'country_code_default' => '52',
                'headers' => [],
                'timeout_seconds' => 20,
            ],
        ]);

        $response->assertRedirect("/admin/clients/{$client->id}/connections");

        $connection->refresh();
        $this->assertSame('https://hooks.treble.ai', $connection->base_url);
        $this->assertSame('/deployment/api/poll/custom/{poll_id}', $connection->settings['send_path'] ?? null);
    }

    public function test_inactive_treble_connection_can_save_without_api_key(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $actor = User::factory()->create();
        $this->grantPermission($actor, 'integrations.manage');

        $client = Client::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'active' => true,
        ]);

        $connection = PlatformConnection::query()->create([
            'client_id' => $client->id,
            'platform_type' => 'treble',
            'name' => 'Treble',
            'slug' => 'treble',
            'base_url' => null,
            'signature_header' => 'X-Treble-Webhook-Secret',
            'webhook_secret' => PlatformConnection::generateWebhookSecret(),
            'credentials' => [],
            'settings' => [
                'send_path' => '/messages/send',
                'auth_mode' => 'bearer_api_key',
            ],
            'active' => false,
        ]);

        $response = $this->actingAs($actor)->put("/admin/clients/{$client->id}/connections/{$connection->id}", [
            'name' => 'Treble',
            'slug' => 'treble',
            'platform_type' => 'treble',
            'base_url' => 'https://main.treble.ai',
            'signature_header' => 'X-Treble-Webhook-Secret',
            'active' => false,
            'credentials' => [],
            'settings' => [
                'send_path' => '/deployment/api/poll/{poll_id}',
                'http_method' => 'POST',
                'auth_mode' => 'bearer_api_key',
                'api_key_header' => 'X-API-Key',
                'country_code_default' => '52',
                'headers' => [],
                'timeout_seconds' => 20,
            ],
        ]);

        $response->assertRedirect("/admin/clients/{$client->id}/connections");

        $connection->refresh();
        $this->assertSame('https://main.treble.ai', $connection->base_url);
        $this->assertFalse($connection->active);
        $this->assertSame('/deployment/api/poll/{poll_id}', $connection->settings['send_path'] ?? null);
    }

    private function grantPermission(User $user, string $permissionSlug): void
    {
        $role = Role::query()->create([
            'name' => 'Lite Role '.$permissionSlug,
            'slug' => 'lite-role-'.str_replace('.', '-', $permissionSlug).'-'.$user->id,
            'description' => 'Temporary role for tests',
        ]);

        $permission = Permission::query()->where('slug', $permissionSlug)->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);
    }
}

<?php

namespace Tests\Feature\Lite;

use App\Models\Client;
use App\Models\Permission;
use App\Models\PlatformConnection;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformConnectionManagementTest extends TestCase
{
    use RefreshDatabase;

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
            'name' => 'Lite Role ' . $permissionSlug,
            'slug' => 'lite-role-' . str_replace('.', '-', $permissionSlug) . '-' . $user->id,
            'description' => 'Temporary role for tests',
        ]);

        $permission = Permission::query()->where('slug', $permissionSlug)->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);
    }
}

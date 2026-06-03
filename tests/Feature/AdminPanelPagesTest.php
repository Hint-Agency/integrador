<?php

namespace Tests\Feature;

use App\Jobs\ExecuteEventJob;
use App\Models\Config;
use App\Models\Event;
use App\Models\EventHttpConfig;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Property;
use App\Models\Record;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminPanelPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_users_page_requires_auth(): void
    {
        $response = $this->get('/admin/users');

        $response->assertRedirect('/login');
    }

    public function test_admin_users_page_returns_403_without_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/users');

        $response->assertStatus(403);
    }

    public function test_admin_users_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $this->grantPermission($user, 'users.manage');

        $response = $this->actingAs($user)->get('/admin/users');

        $response->assertStatus(200);
    }

    public function test_admin_users_create_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $this->grantPermission($user, 'users.manage');

        $response = $this->actingAs($user)->get('/admin/users/create');

        $response->assertStatus(200);
    }

    public function test_admin_roles_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $this->grantPermission($user, 'roles.manage');

        $response = $this->actingAs($user)->get('/admin/roles');

        $response->assertStatus(200);
    }

    public function test_admin_user_create_endpoint_creates_user(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'users.manage');

        $response = $this->actingAs($actor)->post('/admin/users', [
            'username' => 'new.user',
            'first_name' => 'New',
            'last_name' => 'User',
            'email' => 'new.user@example.com',
            'password' => 'password',
            'role_ids' => [],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', [
            'username' => 'new.user',
            'email' => 'new.user@example.com',
            'first_name' => 'New',
            'last_name' => 'User',
        ]);
    }

    public function test_superadmin_role_cannot_be_deleted(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'roles.manage');

        $role = Role::query()->where('slug', 'superadmin')->firstOrFail();

        $response = $this->actingAs($actor)->delete('/admin/roles/'.$role->id);

        $response->assertRedirect();
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'slug' => 'superadmin']);
    }

    public function test_admin_user_update_endpoint_updates_user_data(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $target = User::factory()->create([
            'username' => 'old.user',
            'first_name' => 'Old',
            'last_name' => 'Name',
            'email' => 'old.user@example.com',
        ]);
        $this->grantPermission($actor, 'users.manage');

        $response = $this->actingAs($actor)->put('/admin/users/'.$target->id, [
            'username' => 'updated.user',
            'first_name' => 'Updated',
            'last_name' => 'User',
            'email' => 'updated.user@example.com',
            'password' => '',
            'role_ids' => [],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'username' => 'updated.user',
            'first_name' => 'Updated',
            'last_name' => 'User',
            'email' => 'updated.user@example.com',
        ]);
    }

    public function test_user_cannot_delete_self(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'users.manage');

        $response = $this->actingAs($actor)->delete('/admin/users/'.$actor->id);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $actor->id]);
    }

    public function test_admin_configs_page_masks_sensitive_values(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'configs.manage');

        Config::query()->create([
            'key' => 'api_token',
            'value' => ['token' => 'abc123'],
            'description' => 'Sensitive config',
            'is_encrypted' => false,
        ]);

        $response = $this->actingAs($actor)->get('/admin/configs');

        $response->assertStatus(200);
        $response->assertSee('[redacted]');
        $response->assertDontSee('abc123');
    }

    public function test_admin_events_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'events.manage');

        $response = $this->actingAs($actor)->get('/admin/events');

        $response->assertStatus(200);
    }

    public function test_admin_events_edit_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'events.manage');

        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $event = \App\Models\Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Deal Created',
            'event_type_id' => 'deal.created',
            'type' => 'webhook',
            'active' => true,
        ]);

        $response = $this->actingAs($actor)->get('/admin/events/'.$event->id.'/edit');

        $response->assertStatus(200);
    }

    public function test_admin_event_relationships_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'events.manage');

        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Deal Changed',
            'event_type_id' => 'contact.propertyChange',
            'type' => 'webhook',
            'active' => true,
        ]);

        $response = $this->actingAs($actor)->get("/admin/events/{$event->id}/relationships");

        $response->assertStatus(200);
    }

    public function test_admin_platforms_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'platforms.manage');

        $response = $this->actingAs($actor)->get('/admin/platforms');

        $response->assertStatus(200);
    }

    public function test_admin_properties_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'properties.manage');

        $response = $this->actingAs($actor)->get('/admin/properties');

        $response->assertStatus(200);
    }

    public function test_admin_records_page_is_accessible_with_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'records.view');

        $response = $this->actingAs($actor)->get('/admin/records');

        $response->assertStatus(200);
    }

    public function test_admin_records_page_groups_child_records_under_parent(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'records.view');

        $parent = Record::query()->create([
            'event_type' => 'root.flow',
            'status' => 'success',
            'payload' => [],
            'message' => 'Root flow',
        ]);

        $child = Record::query()->create([
            'record_id' => $parent->id,
            'event_type' => 'child.flow.processing',
            'status' => 'processing',
            'payload' => [],
            'message' => 'Child flow processing',
        ]);

        Record::query()->create([
            'record_id' => $child->id,
            'event_type' => 'grandchild.flow.error',
            'status' => 'error',
            'payload' => [],
            'message' => 'Grandchild flow error',
        ]);

        $response = $this->actingAs($actor)->get('/admin/records?status=error');

        $response->assertStatus(200);
        $response->assertSee('root.flow');
        $response->assertSee('child.flow.processing');
        $response->assertSee('grandchild.flow.error');
    }

    public function test_admin_records_cleanup_deletes_old_records_and_keeps_warnings_errors(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'records.manage');

        $oldSuccess = Record::query()->create([
            'event_type' => 'test.old.success',
            'status' => 'success',
            'payload' => [],
            'message' => 'Old success',
        ]);
        $oldSuccess->forceFill(['created_at' => now()->subDays(45), 'updated_at' => now()->subDays(45)])->save();

        $oldError = Record::query()->create([
            'event_type' => 'test.old.error',
            'status' => 'error',
            'payload' => [],
            'message' => 'Old error',
        ]);
        $oldError->forceFill(['created_at' => now()->subDays(45), 'updated_at' => now()->subDays(45)])->save();

        $recentSuccess = Record::query()->create([
            'event_type' => 'test.recent.success',
            'status' => 'success',
            'payload' => [],
            'message' => 'Recent success',
        ]);

        $response = $this->actingAs($actor)->post('/admin/records/cleanup', [
            'mode' => 'older_than',
            'older_than_days' => 30,
            'keep_warnings_errors' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseMissing('records', ['id' => $oldSuccess->id]);
        $this->assertDatabaseHas('records', ['id' => $oldError->id]);
        $this->assertDatabaseHas('records', ['id' => $recentSuccess->id]);
    }

    public function test_admin_records_cleanup_filtered_mode_requires_a_filter(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'records.manage');

        Record::query()->create([
            'event_type' => 'test.cleanup.guard',
            'status' => 'success',
            'payload' => [],
            'message' => 'Guarded record',
        ]);

        $response = $this->actingAs($actor)->post('/admin/records/cleanup', [
            'mode' => 'filtered',
            'keep_warnings_errors' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('records', 1);
    }

    public function test_admin_event_create_endpoint_creates_event(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'events.manage');

        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $response = $this->actingAs($actor)->post('/admin/events', [
            'platform_id' => $platform->id,
            'name' => 'Deal Created',
            'event_type_id' => 'deal.created',
            'type' => 'webhook',
            'subscription_type' => 'deal.creation',
            'method_name' => 'companyCreatedWebhook',
            'endpoint_api' => null,
            'active' => true,
            'payload_mapping' => [],
            'meta' => [],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('events', [
            'name' => 'Deal Created',
            'event_type_id' => 'deal.created',
            'platform_id' => $platform->id,
        ]);
    }

    public function test_admin_event_create_endpoint_stores_schedule_and_http_config(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'events.manage');

        $platform = Platform::query()->create([
            'name' => 'Generic',
            'slug' => 'generic',
            'type' => 'generic',
            'active' => true,
        ]);

        $response = $this->actingAs($actor)->post('/admin/events', [
            'platform_id' => $platform->id,
            'name' => 'Nightly Sync',
            'event_type_id' => 'generic.external.call',
            'type' => 'schedule',
            'schedule_expression' => '*/5 * * * *',
            'command_sql' => 'select 1',
            'enable_update_hubdb' => true,
            'hubdb_table_id' => 45,
            'payload_mapping' => [],
            'meta' => [],
            'http_config' => [
                'method' => 'POST',
                'base_url' => 'https://api.example.com',
                'path' => '/v1/sync',
                'headers_json' => ['x-tenant' => 'acme'],
                'query_json' => ['source' => 'integrador'],
                'auth_mode' => 'bearer_api_key',
                'auth_config_json' => ['api_key_env' => 'GENERIC_API_KEY'],
                'timeout_seconds' => 40,
                'retry_policy_json' => ['max_attempts' => 3],
                'idempotency_config_json' => ['enabled' => true],
                'allowlist_domains_json' => ['api.example.com'],
                'active' => true,
            ],
        ]);

        $response->assertRedirect();
        $event = \App\Models\Event::query()->where('name', 'Nightly Sync')->firstOrFail();

        $this->assertSame('*/5 * * * *', $event->schedule_expression);
        $this->assertTrue((bool) $event->enable_update_hubdb);
        $this->assertSame(45, $event->hubdb_table_id);

        $this->assertDatabaseHas('event_http_configs', [
            'event_id' => $event->id,
            'method' => 'POST',
            'base_url' => 'https://api.example.com',
            'path' => '/v1/sync',
            'auth_mode' => 'bearer_api_key',
            'timeout_seconds' => 40,
        ]);

        $httpConfig = EventHttpConfig::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame('acme', $httpConfig->headers_json['x-tenant'] ?? null);
    }

    public function test_admin_event_execute_now_endpoint_enqueues_schedule_event(): void
    {
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $actor = User::factory()->create();
        $this->grantPermission($actor, 'events.manage');

        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $event = \App\Models\Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Nightly Sync',
            'event_type_id' => 'generic.external.call',
            'type' => 'schedule',
            'schedule_expression' => '*/5 * * * *',
            'active' => true,
        ]);

        $response = $this->actingAs($actor)->post('/admin/events/'.$event->id.'/execute-now');

        $response->assertRedirect('/admin/events');
        $response->assertSessionHas('success');
        Queue::assertPushed(ExecuteEventJob::class);
    }

    public function test_admin_platform_create_endpoint_creates_platform(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'platforms.manage');

        $response = $this->actingAs($actor)->post('/admin/platforms', [
            'name' => 'Generic API',
            'slug' => 'generic-api',
            'type' => 'generic',
            'signature' => 'x-signature',
            'secret_key' => 'secret',
            'active' => true,
            'credentials' => ['api_key' => 'abc'],
            'settings' => ['base_url' => 'https://api.example.com'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('platforms', [
            'name' => 'Generic API',
            'slug' => 'generic-api',
            'type' => 'generic',
        ]);
    }

    public function test_admin_platform_update_endpoint_stores_nested_settings(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'platforms.manage');

        $platform = Platform::query()->create([
            'name' => 'Odoo',
            'slug' => 'odoo',
            'type' => 'odoo',
            'active' => true,
            'credentials' => [
                'username' => 'demo',
                'password' => 'secret',
                'database' => 'directogroup',
            ],
            'settings' => [
                'url' => 'https://old-odoo.example.com',
            ],
        ]);

        $response = $this->actingAs($actor)->put('/admin/platforms/'.$platform->id, [
            'name' => 'Odoo DirectoGroup',
            'slug' => 'odoo-directogroup',
            'type' => 'odoo',
            'signature' => null,
            'secret_key' => null,
            'active' => true,
            'credentials' => [
                'username' => 'demo',
                'password' => 'secret',
                'database' => 'directogroup',
            ],
            'settings' => [
                'url' => 'https://odoo.example.com',
                'odoo' => [
                    'catalogs' => [
                        'taxes' => [
                            'IVA16' => 4,
                        ],
                    ],
                    'defaults' => [
                        'company_id' => 1,
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        $settings = $platform->fresh()->settings;

        $this->assertSame('https://odoo.example.com', $settings['url']);
        $this->assertSame(4, $settings['odoo']['catalogs']['taxes']['IVA16']);
        $this->assertSame(1, $settings['odoo']['defaults']['company_id']);
    }

    public function test_admin_platform_test_connection_endpoint_returns_error_message_when_not_configured(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'platforms.manage');

        config()->set('hubspot.access_token', null);

        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $response = $this->actingAs($actor)->post('/admin/platforms/'.$platform->id.'/test-connection');

        $response->assertRedirect('/admin/platforms');
        $response->assertSessionHas('error');
    }

    public function test_admin_property_create_endpoint_creates_property(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'properties.manage');

        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $response = $this->actingAs($actor)->post('/admin/properties', [
            'platform_id' => $platform->id,
            'name' => 'Document',
            'key' => 'document_url',
            'type' => 'file',
            'required' => true,
            'active' => true,
            'meta' => ['source' => 'hubspot'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('properties', [
            'platform_id' => $platform->id,
            'name' => 'Document',
            'key' => 'document_url',
            'type' => 'file',
        ]);
    }

    public function test_admin_event_relationship_create_endpoint_creates_mapping(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'events.manage');

        $sourcePlatform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $targetPlatform = Platform::query()->create([
            'name' => 'Odoo',
            'slug' => 'odoo',
            'type' => 'odoo',
            'active' => true,
        ]);

        $targetEvent = Event::query()->create([
            'platform_id' => $targetPlatform->id,
            'name' => 'Create Sale Order',
            'event_type_id' => 'sale_order.created',
            'type' => 'webhook',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $sourcePlatform->id,
            'to_event_id' => $targetEvent->id,
            'name' => 'Deal Changed',
            'event_type_id' => 'contact.propertyChange',
            'type' => 'webhook',
            'active' => true,
        ]);

        $sourceProperty = Property::query()->create([
            'platform_id' => $sourcePlatform->id,
            'name' => 'Amount',
            'key' => 'properties.amount',
            'type' => 'float',
            'required' => false,
            'active' => true,
        ]);

        $targetProperty = Property::query()->create([
            'platform_id' => $targetPlatform->id,
            'name' => 'Total',
            'key' => 'total',
            'type' => 'float',
            'required' => false,
            'active' => true,
        ]);

        $response = $this->actingAs($actor)->post("/admin/events/{$event->id}/relationships", [
            'property_id' => $sourceProperty->id,
            'related_property_id' => $targetProperty->id,
            'mapping_key' => 'payload.amount',
            'active' => true,
            'meta' => ['transform' => 'decimal'],
        ]);

        $response->assertRedirect("/admin/events/{$event->id}/relationships");
        $this->assertDatabaseHas('property_relationships', [
            'event_id' => $event->id,
            'property_id' => $sourceProperty->id,
            'related_property_id' => $targetProperty->id,
            'mapping_key' => 'payload.amount',
            'active' => true,
        ]);
    }

    public function test_admin_event_relationship_create_endpoint_allows_upstream_source_for_intermediate_event(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'events.manage');

        $hubspot = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $odoo = Platform::query()->create([
            'name' => 'Odoo',
            'slug' => 'odoo',
            'type' => 'odoo',
            'active' => true,
        ]);

        $writeback = Event::query()->create([
            'platform_id' => $hubspot->id,
            'name' => 'Write Back Source Quote',
            'event_type_id' => 'object.updated',
            'type' => 'node',
            'active' => true,
        ]);

        $subscription = Event::query()->create([
            'platform_id' => $odoo->id,
            'to_event_id' => $writeback->id,
            'name' => 'Create Destination Quote/Subscription',
            'event_type_id' => 'invoice.recurring.created',
            'type' => 'node',
            'method_name' => 'createSaleSubscription',
            'active' => true,
        ]);

        Event::query()->create([
            'platform_id' => $hubspot->id,
            'to_event_id' => $subscription->id,
            'name' => 'Fetch Signed Quotes',
            'event_type_id' => 'quotes.sending_data',
            'type' => 'schedule',
            'active' => true,
        ]);

        $sourceProperty = Property::query()->create([
            'platform_id' => $hubspot->id,
            'name' => 'Terms',
            'key' => 'hs_terms',
            'type' => 'string',
            'required' => false,
            'active' => true,
        ]);

        $targetProperty = Property::query()->create([
            'platform_id' => $odoo->id,
            'name' => 'Odoo Note',
            'key' => 'note',
            'type' => 'string',
            'required' => false,
            'active' => true,
        ]);

        $response = $this->actingAs($actor)->post("/admin/events/{$subscription->id}/relationships", [
            'property_id' => $sourceProperty->id,
            'related_property_id' => $targetProperty->id,
            'mapping_key' => 'raw.properties.hs_terms',
            'active' => true,
            'meta' => ['scope' => 'sale_subscription'],
        ]);

        $response->assertRedirect("/admin/events/{$subscription->id}/relationships");
        $this->assertDatabaseHas('property_relationships', [
            'event_id' => $subscription->id,
            'property_id' => $sourceProperty->id,
            'related_property_id' => $targetProperty->id,
            'mapping_key' => 'raw.properties.hs_terms',
            'active' => true,
        ]);
    }

    public function test_admin_event_relationships_page_lists_upstream_source_and_current_target_for_intermediate_event(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'events.manage');

        $hubspot = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $odoo = Platform::query()->create([
            'name' => 'Odoo',
            'slug' => 'odoo',
            'type' => 'odoo',
            'active' => true,
        ]);

        $writeback = Event::query()->create([
            'platform_id' => $hubspot->id,
            'name' => 'Write Back Source Quote',
            'event_type_id' => 'object.updated',
            'type' => 'node',
            'active' => true,
        ]);

        $subscription = Event::query()->create([
            'platform_id' => $odoo->id,
            'to_event_id' => $writeback->id,
            'name' => 'Create Destination Quote/Subscription',
            'event_type_id' => 'invoice.recurring.created',
            'type' => 'node',
            'active' => true,
        ]);

        Event::query()->create([
            'platform_id' => $hubspot->id,
            'to_event_id' => $subscription->id,
            'name' => 'Fetch Signed Quotes',
            'event_type_id' => 'quotes.sending_data',
            'type' => 'schedule',
            'active' => true,
        ]);

        Property::query()->create([
            'platform_id' => $hubspot->id,
            'name' => 'Terms',
            'key' => 'hs_terms',
            'type' => 'string',
            'required' => false,
            'active' => true,
        ]);

        Property::query()->create([
            'platform_id' => $odoo->id,
            'name' => 'Odoo Note',
            'key' => 'note',
            'type' => 'string',
            'required' => false,
            'active' => true,
        ]);

        $response = $this->actingAs($actor)->get("/admin/events/{$subscription->id}/relationships");

        $response->assertStatus(200);
        $response->assertSee('Terms');
        $response->assertSee('Odoo Note');
        $response->assertSee('incoming_to_current_with_next');
    }

    public function test_admin_category_create_endpoint_assigns_properties(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $this->grantPermission($actor, 'categories.manage');

        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $property = Property::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Category Field',
            'key' => 'category_field',
            'type' => 'string',
            'required' => false,
            'active' => true,
        ]);

        $response = $this->actingAs($actor)->post('/admin/categories', [
            'name' => 'Ops',
            'slug' => 'ops',
            'description' => 'Operations',
            'active' => true,
            'property_ids' => [$property->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('categories', ['slug' => 'ops']);
        $this->assertDatabaseHas('category_property', ['property_id' => $property->id]);
    }

    private function grantPermission(User $user, string $permissionSlug): void
    {
        $role = Role::query()->create([
            'name' => 'Temp Role '.$permissionSlug,
            'slug' => 'temp-role-'.str_replace('.', '-', $permissionSlug),
            'description' => 'Temporary role for tests',
        ]);

        $permission = Permission::query()->where('slug', $permissionSlug)->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventHttpConfig;
use App\Models\Platform;
use App\Services\Aspel\AspelService;
use App\Services\EventProcessingService;
use App\Services\Generic\AuthStrategyResolver;
use App\Services\Generic\GenericHttpAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AspelServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_processing_service_resolves_aspel_service_for_generic_aspel_platform(): void
    {
        $platform = Platform::query()->create([
            'name' => 'ASPEL Fertifarma',
            'slug' => 'aspel-fertifarma',
            'type' => 'generic',
            'active' => true,
        ]);

        $serviceClass = app(EventProcessingService::class)->getServiceClass($platform);

        $this->assertSame(AspelService::class, $serviceClass);
    }

    public function test_it_excludes_technical_sync_fields_from_aspel_request_body(): void
    {
        $platform = Platform::query()->create([
            'name' => 'ASPEL Fertifarma',
            'slug' => 'aspel-fertifarma',
            'type' => 'generic',
            'credentials' => [
                'api_key' => 'token_123',
            ],
            'settings' => [
                'service_driver' => 'aspel',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Sync Contact ASPEL',
            'event_type_id' => 'generic.external.call',
            'type' => 'webhook',
            'active' => true,
        ]);

        EventHttpConfig::query()->create([
            'event_id' => $event->id,
            'method' => 'POST',
            'base_url' => 'https://api.example.com',
            'path' => '/contacts',
            'active' => true,
        ]);

        $service = new AspelService(
            $platform,
            app(AuthStrategyResolver::class),
            $event,
            null,
        );

        $body = $service->resolveBody($event, [
            'nombre' => 'Demo Integrador',
            'rfc' => 'RFC123',
            'clave' => '50900',
            'sync_to_aspel' => 'pending',
            'sync_status_aspel' => 'success',
            'last_sync_aspel' => '2026-04-07T10:00:00Z',
            'last_error_aspel' => 'none',
            'hubspot_object_id' => '208589143093',
            'destination_response' => ['data' => ['clave' => '50900']],
            'source_event_id' => 5,
        ]);

        $this->assertSame([
            'nombre' => 'Demo Integrador',
            'rfc' => 'RFC123',
            'clave' => '50900',
        ], $body);
    }

    public function test_it_executes_create_contact_against_contacts_endpoint(): void
    {
        $platform = Platform::query()->create([
            'name' => 'ASPEL Fertifarma',
            'slug' => 'aspel-fertifarma',
            'type' => 'generic',
            'credentials' => [
                'api_key' => 'token_123',
            ],
            'settings' => [
                'service_driver' => 'aspel',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Create Contact ASPEL',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'createContact',
            'type' => 'webhook',
            'active' => true,
        ]);

        EventHttpConfig::query()->create([
            'event_id' => $event->id,
            'method' => 'POST',
            'base_url' => 'https://api.example.com',
            'path' => '/contacts',
            'active' => true,
        ]);

        $service = new AspelService(
            $platform,
            app(AuthStrategyResolver::class),
            $event,
            null,
        );

        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->withArgs(function (
            string $platformKey,
            string $endpoint,
            string $method,
            array $headers,
            array $query,
            array $body
        ): bool {
            return $platformKey === 'aspel'
                && $endpoint === 'https://api.example.com/contacts'
                && $method === 'POST'
                && ($body['nombre'] ?? null) === 'Demo Integrador'
                && ! array_key_exists('sync_to_aspel', $body);
        })->andReturn([
            'success' => true,
            'status_code' => 201,
            'retryable' => false,
            'request_id' => 'req_aspel_1',
            'external_id' => '50900',
            'latency_ms' => 10,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/contacts',
            'method' => 'POST',
            'data' => ['clave' => '50900'],
            'error' => [
                'code' => null,
                'message' => null,
                'details' => null,
            ],
        ]);

        $response = $service->executeEndpointCall([
            'nombre' => 'Demo Integrador',
            'sync_to_aspel' => 'pending',
        ], $adapter);

        $this->assertTrue($response['success']);
        $this->assertSame('50900', $response['external_id']);
    }

    public function test_it_executes_update_contact_with_put_and_clave_path(): void
    {
        $platform = Platform::query()->create([
            'name' => 'ASPEL Fertifarma',
            'slug' => 'aspel-fertifarma',
            'type' => 'generic',
            'credentials' => [
                'api_key' => 'token_123',
            ],
            'settings' => [
                'service_driver' => 'aspel',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Update Contact ASPEL',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'updateContact',
            'type' => 'webhook',
            'active' => true,
        ]);

        EventHttpConfig::query()->create([
            'event_id' => $event->id,
            'method' => 'POST',
            'base_url' => 'https://api.example.com',
            'path' => '/contacts',
            'active' => true,
        ]);

        $service = new AspelService(
            $platform,
            app(AuthStrategyResolver::class),
            $event,
            null,
        );

        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->withArgs(function (
            string $platformKey,
            string $endpoint,
            string $method,
            array $headers,
            array $query,
            array $body
        ): bool {
            return $platformKey === 'aspel'
                && $endpoint === 'https://api.example.com/contacts/50902'
                && $method === 'PUT'
                && ! array_key_exists('clave', $body)
                && ($body['telefono'] ?? null) === '5550001111';
        })->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_aspel_2',
            'external_id' => '50902',
            'latency_ms' => 12,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/contacts/50902',
            'method' => 'PUT',
            'data' => [
                'success' => true,
                'operation' => 'updated',
                'clave' => '50902',
                'message' => 'Contact updated successfully',
            ],
            'error' => [
                'code' => null,
                'message' => null,
                'details' => null,
            ],
        ]);

        $response = $service->executeEndpointCall([
            'clave' => '50902',
            'telefono' => '5550001111',
            'sync_to_aspel' => 'pending',
        ], $adapter);

        $this->assertTrue($response['success']);
        $this->assertSame('PUT', $response['method']);
    }

    public function test_it_replaces_custom_update_placeholder_and_excludes_clave_from_update_body(): void
    {
        $platform = Platform::query()->create([
            'name' => 'ASPEL Fertifarma',
            'slug' => 'aspel-fertifarma',
            'type' => 'generic',
            'credentials' => [
                'api_key' => 'token_123',
            ],
            'settings' => [
                'service_driver' => 'aspel',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Update Contact ASPEL Placeholder',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'updateContact',
            'type' => 'webhook',
            'active' => true,
        ]);

        EventHttpConfig::query()->create([
            'event_id' => $event->id,
            'method' => 'PUT',
            'base_url' => 'https://api.example.com',
            'path' => '/contacts/{itemId}',
            'active' => true,
        ]);

        $service = new AspelService(
            $platform,
            app(AuthStrategyResolver::class),
            $event,
            null,
        );

        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->withArgs(function (
            string $platformKey,
            string $endpoint,
            string $method,
            array $headers,
            array $query,
            array $body
        ): bool {
            return $platformKey === 'aspel'
                && $endpoint === 'https://api.example.com/contacts/50902'
                && $method === 'PUT'
                && ! array_key_exists('clave', $body)
                && ($body['telefono'] ?? null) === '5550001111';
        })->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_aspel_3',
            'external_id' => '50902',
            'latency_ms' => 8,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/contacts/50902',
            'method' => 'PUT',
            'data' => ['success' => true, 'operation' => 'updated', 'clave' => '50902'],
            'error' => [
                'code' => null,
                'message' => null,
                'details' => null,
            ],
        ]);

        $response = $service->executeEndpointCall([
            'clave' => '50902',
            'telefono' => '5550001111',
        ], $adapter);

        $this->assertTrue($response['success']);
        $this->assertSame('PUT', $response['method']);
    }

    public function test_it_uses_contacts_search_endpoint_even_when_update_path_has_placeholder(): void
    {
        $platform = Platform::query()->create([
            'name' => 'ASPEL Fertifarma',
            'slug' => 'aspel-fertifarma',
            'type' => 'generic',
            'credentials' => ['api_key' => 'token_123'],
            'settings' => ['service_driver' => 'aspel'],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Update Contact ASPEL Placeholder Search',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'updateContactWithLookup',
            'type' => 'webhook',
            'active' => true,
        ]);

        EventHttpConfig::query()->create([
            'event_id' => $event->id,
            'method' => 'PUT',
            'base_url' => 'https://api.example.com',
            'path' => '/contacts/{clave}',
            'active' => true,
        ]);

        $service = new AspelService($platform, app(AuthStrategyResolver::class), $event, null);

        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->withArgs(function (
            string $platformKey,
            string $endpoint,
            string $method,
            array $headers,
            array $query
        ): bool {
            return $platformKey === 'aspel'
                && $endpoint === 'https://api.example.com/contacts/search'
                && $method === 'GET'
                && $query === ['rfc' => 'RFC123'];
        })->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_lookup_placeholder',
            'external_id' => null,
            'latency_ms' => 7,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/contacts/search',
            'method' => 'GET',
            'data' => [
                'found' => false,
                'count' => 0,
                'criteriaUsed' => 'rfc',
                'item' => null,
                'items' => null,
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ]);

        $response = $service->updateContactWithLookup([
            'rfc' => 'RFC123',
        ], $adapter);

        $this->assertTrue($response['success']);
        $this->assertSame(1, data_get($response, 'data.not_found_count'));
    }

    public function test_it_looks_up_contact_before_updating_in_aspel(): void
    {
        $platform = Platform::query()->create([
            'name' => 'ASPEL Fertifarma',
            'slug' => 'aspel-fertifarma',
            'type' => 'generic',
            'credentials' => ['api_key' => 'token_123'],
            'settings' => ['service_driver' => 'aspel'],
            'active' => true,
        ]);

        $createEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Create Contact ASPEL',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'createContact',
            'type' => 'webhook',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'to_event_id' => $createEvent->id,
            'name' => 'Update Contact ASPEL',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'updateContactWithLookup',
            'type' => 'webhook',
            'active' => true,
        ]);

        EventHttpConfig::query()->create([
            'event_id' => $event->id,
            'method' => 'POST',
            'base_url' => 'https://api.example.com',
            'path' => '/contacts',
            'active' => true,
        ]);

        $service = new AspelService($platform, app(AuthStrategyResolver::class), $event, null);

        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->withArgs(function (
            string $platformKey,
            string $endpoint,
            string $method,
            array $headers,
            array $query
        ): bool {
            return $platformKey === 'aspel'
                && $endpoint === 'https://api.example.com/contacts/search'
                && $method === 'GET'
                && $query === ['rfc' => 'RFC123', 'phone' => '5550001111', 'email' => 'demo@example.com'];
        })->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_lookup_1',
            'external_id' => '50902',
            'latency_ms' => 10,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/contacts/search',
            'method' => 'GET',
            'data' => [
                'found' => true,
                'count' => 1,
                'criteriaUsed' => 'rfc',
                'item' => [
                    'clave' => '50902',
                    'rfc' => 'RFC123',
                ],
                'items' => null,
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();
        $adapter->shouldReceive('send')->once()->withArgs(function (
            string $platformKey,
            string $endpoint,
            string $method,
            array $headers,
            array $query,
            array $body
        ): bool {
            return $platformKey === 'aspel'
                && $endpoint === 'https://api.example.com/contacts/50902'
                && $method === 'PUT'
                && ! array_key_exists('clave', $body)
                && ($body['aspel_lookup']['criteriaUsed'] ?? null) === 'rfc';
        })->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_update_1',
            'external_id' => '50902',
            'latency_ms' => 12,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/contacts/50902',
            'method' => 'PUT',
            'data' => ['success' => true, 'operation' => 'updated', 'clave' => '50902'],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();

        $response = $service->updateContactWithLookup([
            'rfc' => 'RFC123',
            'phone' => '5550001111',
            'email' => 'demo@example.com',
            'nombre' => 'Demo Integrador',
        ], $adapter);

        $this->assertTrue($response['success']);
        $this->assertSame(1, data_get($response, 'data.updated_count'));
        $this->assertSame('rfc', data_get($response, 'data.matched_by'));
    }

    public function test_it_prepares_create_fallback_when_aspel_lookup_does_not_find_contact(): void
    {
        $platform = Platform::query()->create([
            'name' => 'ASPEL Fertifarma',
            'slug' => 'aspel-fertifarma',
            'type' => 'generic',
            'credentials' => ['api_key' => 'token_123'],
            'settings' => ['service_driver' => 'aspel'],
            'active' => true,
        ]);

        $createEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Create Contact ASPEL',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'createContact',
            'type' => 'webhook',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'to_event_id' => $createEvent->id,
            'name' => 'Update Contact ASPEL',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'updateContactWithLookup',
            'type' => 'webhook',
            'active' => true,
        ]);

        EventHttpConfig::query()->create([
            'event_id' => $event->id,
            'method' => 'POST',
            'base_url' => 'https://api.example.com',
            'path' => '/contacts',
            'active' => true,
        ]);

        $service = new AspelService($platform, app(AuthStrategyResolver::class), $event, null);

        $payload = [
            'rfc' => 'RFC123',
            'email' => 'demo@example.com',
            'nombre' => 'Demo Integrador',
        ];

        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_lookup_2',
            'external_id' => null,
            'latency_ms' => 9,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/contacts/search',
            'method' => 'GET',
            'data' => [
                'found' => false,
                'count' => 0,
                'criteriaUsed' => 'email',
                'item' => null,
                'items' => null,
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();

        $response = $service->updateContactWithLookup($payload, $adapter);

        $this->assertTrue($response['success']);
        $this->assertNull($response['status'] ?? null);
        $this->assertSame(1, data_get($response, 'data.not_found_count'));
        $this->assertSame($payload, data_get($response, 'data.output_payload'));
    }

    public function test_it_prepares_create_fallback_when_aspel_lookup_returns_contact_not_found_404(): void
    {
        $platform = Platform::query()->create([
            'name' => 'ASPEL Fertifarma',
            'slug' => 'aspel-fertifarma',
            'type' => 'generic',
            'credentials' => ['api_key' => 'token_123'],
            'settings' => ['service_driver' => 'aspel'],
            'active' => true,
        ]);

        $createEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Create Contact ASPEL',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'createContact',
            'type' => 'webhook',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'to_event_id' => $createEvent->id,
            'name' => 'Update Contact ASPEL',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'updateContactWithLookup',
            'type' => 'webhook',
            'active' => true,
        ]);

        EventHttpConfig::query()->create([
            'event_id' => $event->id,
            'method' => 'POST',
            'base_url' => 'https://api.example.com',
            'path' => '/contacts',
            'active' => true,
        ]);

        $service = new AspelService($platform, app(AuthStrategyResolver::class), $event, null);

        $payload = [
            'rfc' => 'RFC123',
            'phone' => '5550001111',
            'email' => 'demo@example.com',
            'nombre' => 'Demo Integrador',
        ];

        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => false,
            'status_code' => 404,
            'retryable' => false,
            'request_id' => 'req_lookup_404',
            'external_id' => null,
            'latency_ms' => 12,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/contacts/search',
            'method' => 'GET',
            'data' => [
                'clave' => 'search',
                'error' => 'Contact not found',
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();

        $response = $service->updateContactWithLookup($payload, $adapter);

        $this->assertTrue($response['success']);
        $this->assertNull($response['status'] ?? null);
        $this->assertSame(1, data_get($response, 'data.not_found_count'));
        $this->assertSame($payload, data_get($response, 'data.output_payload'));
    }

    public function test_it_returns_warning_when_aspel_lookup_finds_multiple_contacts(): void
    {
        $platform = Platform::query()->create([
            'name' => 'ASPEL Fertifarma',
            'slug' => 'aspel-fertifarma',
            'type' => 'generic',
            'credentials' => ['api_key' => 'token_123'],
            'settings' => ['service_driver' => 'aspel'],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Update Contact ASPEL',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'updateContactWithLookup',
            'type' => 'webhook',
            'active' => true,
        ]);

        EventHttpConfig::query()->create([
            'event_id' => $event->id,
            'method' => 'POST',
            'base_url' => 'https://api.example.com',
            'path' => '/contacts',
            'active' => true,
        ]);

        $service = new AspelService($platform, app(AuthStrategyResolver::class), $event, null);

        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_lookup_3',
            'external_id' => null,
            'latency_ms' => 9,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/contacts/search',
            'method' => 'GET',
            'data' => [
                'found' => true,
                'count' => 2,
                'criteriaUsed' => 'phone',
                'item' => null,
                'items' => [
                    ['clave' => '50902'],
                    ['clave' => '50944'],
                ],
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ]);

        $response = $service->updateContactWithLookup([
            'phone' => '5550001111',
            'nombre' => 'Demo Integrador',
        ], $adapter);

        $this->assertTrue($response['success']);
        $this->assertSame('warning', $response['status']);
        $this->assertSame('multiple_matches', data_get($response, 'data.warning_reason'));
        $this->assertSame([], data_get($response, 'data.output_payload'));
    }

    public function test_it_supports_polling_updated_contacts_with_explicit_get_operation(): void
    {
        $platform = Platform::query()->create([
            'name' => 'ASPEL Fertifarma',
            'slug' => 'aspel-fertifarma',
            'type' => 'generic',
            'credentials' => [
                'api_key' => 'token_123',
            ],
            'settings' => [
                'service_driver' => 'aspel',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Poll Updated Contacts ASPEL',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'getUpdatedContacts',
            'type' => 'schedule',
            'active' => true,
        ]);

        EventHttpConfig::query()->create([
            'event_id' => $event->id,
            'method' => 'GET',
            'base_url' => 'https://api.example.com',
            'path' => '/contacts/updated',
            'active' => true,
        ]);

        $service = new AspelService(
            $platform,
            app(AuthStrategyResolver::class),
            $event,
            null,
        );

        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->withArgs(function (
            string $platformKey,
            string $endpoint,
            string $method
        ): bool {
            return $platformKey === 'aspel'
                && $endpoint === 'https://api.example.com/contacts/updated'
                && $method === 'GET';
        })->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_poll_1',
            'external_id' => null,
            'latency_ms' => 8,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/contacts/updated',
            'method' => 'GET',
            'data' => [
                ['clave' => '50900', 'nombre' => 'Demo Integrador'],
            ],
            'error' => [
                'code' => null,
                'message' => null,
                'details' => null,
            ],
        ]);

        $response = $service->executeEndpointCall([
            'updated_since' => '2026-04-07T00:00:00Z',
        ], $adapter);

        $this->assertTrue($response['success']);
        $this->assertSame('GET', $response['method']);
    }

    public function test_it_syncs_line_item_inventory_using_selected_warehouses(): void
    {
        $platform = Platform::query()->create([
            'name' => 'ASPEL Fertifarma',
            'slug' => 'aspel-fertifarma',
            'type' => 'generic',
            'credentials' => [
                'api_key' => 'token_123',
            ],
            'settings' => [
                'service_driver' => 'aspel',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Sync Line Item Warehouse Inventory',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'syncLineItemWarehouseInventory',
            'type' => 'webhook',
            'meta' => [
                'article_property' => 'clave',
                'warehouse_property' => 'almacen_id',
            ],
            'active' => true,
        ]);

        EventHttpConfig::query()->create([
            'event_id' => $event->id,
            'method' => 'GET',
            'base_url' => 'https://api.example.com',
            'path' => '/api/almacenes/{cveArticulo}',
            'auth_mode' => 'bearer_api_key',
            'auth_config_json' => [
                'header_name' => 'x-api-key',
                'header_prefix' => '',
            ],
            'active' => true,
        ]);

        $service = new AspelService(
            $platform,
            app(AuthStrategyResolver::class),
            $event,
            null,
        );

        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->withArgs(function (
            string $platformKey,
            string $endpoint,
            string $method,
            array $headers,
            array $query,
            array $body
        ): bool {
            return $platformKey === 'aspel'
                && $endpoint === 'https://api.example.com/api/almacenes/ABC123'
                && $method === 'GET'
                && ($headers['x-api-key'] ?? null) === 'token_123'
                && $query === []
                && $body === [];
        })->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_warehouse_1',
            'external_id' => null,
            'latency_ms' => 10,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/api/almacenes/ABC123',
            'method' => 'GET',
            'data' => [
                [
                    'cveArt' => 'ABC123',
                    'cveAlm' => '001',
                    'exist' => 12.5,
                    'stockMax' => 100,
                    'stockMin' => 10,
                ],
                [
                    'cveArt' => 'ABC123',
                    'cveAlm' => '002',
                    'exist' => 5.0,
                    'stockMax' => 50,
                    'stockMin' => 5,
                ],
                [
                    'cveArt' => 'ABC123',
                    'cveAlm' => '003',
                    'exist' => 99.0,
                    'stockMax' => 999,
                    'stockMin' => 1,
                ],
            ],
            'error' => [
                'code' => null,
                'message' => null,
                'details' => null,
            ],
        ]);

        $response = $service->syncLineItemWarehouseInventory([
            'hubspot_object_id' => 'line_1',
            'clave' => 'ABC123',
            'almacen_id' => '001,002',
        ], $adapter);

        $this->assertTrue($response['success']);
        $this->assertSame(17.5, $response['data']['existencias']);
        $this->assertSame(150.0, $response['data']['stock_maximo']);
        $this->assertSame(15.0, $response['data']['stock_minimo']);
        $this->assertSame(['001', '002'], $response['data']['selected_warehouses']);
        $this->assertSame(['001', '002'], $response['data']['matched_warehouses']);
    }
}

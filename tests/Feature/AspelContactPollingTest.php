<?php

namespace Tests\Feature;

use App\Models\Config;
use App\Models\Event;
use App\Models\EventHttpConfig;
use App\Models\EventIdempotencyKey;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use App\Models\Record;
use App\Services\Aspel\AspelService;
use App\Services\Generic\AuthStrategyResolver;
use App\Services\Generic\GenericHttpAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AspelContactPollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_processes_changed_contacts_and_persists_cursor_on_success(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        [$aspelPlatform, $scheduleEvent, $record] = $this->preparePollingContext();

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts/search') {
                $filter = $request->data()['filterGroups'][0]['filters'][0] ?? [];
                if (($filter['propertyName'] ?? null) === 'clave' && ($filter['value'] ?? null) === '50902') {
                    return Http::response([
                        'results' => [[
                            'id' => '401',
                            'properties' => ['clave' => '50902'],
                        ]],
                    ], 200);
                }
            }

            if ($request->method() === 'PATCH' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts/401') {
                return Http::response([
                    'id' => '401',
                    'properties' => $request->data()['properties'] ?? [],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_changes_1',
            'external_id' => null,
            'latency_ms' => 10,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/api/contacts/changes',
            'method' => 'GET',
            'data' => [
                'items' => [[
                    'clave' => '50902',
                    'nombre' => 'JUAN CARLOS LOPEZ MARTINEZ',
                    'rfc' => 'LOMJ850214H12',
                    'status' => 'A',
                    'versionSinc' => '2026-05-07 10:00:00',
                ]],
                'nextSinceTs' => '2026-05-07 10:00:00',
                'nextSinceClave' => '50902',
                'hasMore' => false,
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_detail_1',
            'external_id' => '50902',
            'latency_ms' => 8,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/api/contacts/50902',
            'method' => 'GET',
            'data' => [
                'clave' => '50902',
                'nombre' => 'JUAN CARLOS LOPEZ MARTINEZ',
                'rfc' => 'LOMJ850214H12',
                'telefono' => '5551234567',
                'emailEnvio' => 'juan.lopez@example.com',
                'status' => 'A',
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();

        $service = new AspelService(
            $aspelPlatform,
            app(AuthStrategyResolver::class),
            $scheduleEvent,
            $record,
        );

        $result = $service->getUpdatedContacts([], $adapter);

        $this->assertTrue($result['success'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame('2026-05-07 10:00:00', data_get($result, 'data.cursor.sinceTs'));
        $this->assertSame('50902', data_get($result, 'data.cursor.sinceClave'));
        $this->assertSame('success', data_get(Config::query()->where('key', 'aspel.contacts.cursor.' . $scheduleEvent->id . '.last_run_status')->first()?->value, 'value'));
        $this->assertSame('2026-05-07 10:00:00', data_get(Config::query()->where('key', 'aspel.contacts.cursor.' . $scheduleEvent->id . '.since_ts')->first()?->value, 'value'));
        $this->assertSame('50902', data_get(Config::query()->where('key', 'aspel.contacts.cursor.' . $scheduleEvent->id . '.since_clave')->first()?->value, 'value'));

        $this->assertDatabaseHas('event_idempotency_keys', [
            'event_id' => $scheduleEvent->id,
            'status' => 'success',
        ]);

        $this->assertDatabaseHas('records', [
            'record_id' => $record->id,
            'status' => 'success',
            'event_type' => 'object.updated',
        ]);
    }

    public function test_it_does_not_advance_cursor_when_hubspot_sync_fails(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        [$aspelPlatform, $scheduleEvent, $record] = $this->preparePollingContext();

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts/search') {
                return Http::response(['results' => []], 200);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts') {
                return Http::response([
                    'status' => 'error',
                    'message' => 'HubSpot validation failed',
                    'category' => 'VALIDATION_ERROR',
                ], 400);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_changes_1',
            'external_id' => null,
            'latency_ms' => 10,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/api/contacts/changes',
            'method' => 'GET',
            'data' => [
                'items' => [[
                    'clave' => '50902',
                    'nombre' => 'JUAN CARLOS LOPEZ MARTINEZ',
                    'rfc' => 'LOMJ850214H12',
                    'status' => 'A',
                    'versionSinc' => '2026-05-07 10:00:00',
                ]],
                'nextSinceTs' => '2026-05-07 10:00:00',
                'nextSinceClave' => '50902',
                'hasMore' => false,
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_detail_1',
            'external_id' => '50902',
            'latency_ms' => 8,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/api/contacts/50902',
            'method' => 'GET',
            'data' => [
                'clave' => '50902',
                'nombre' => 'JUAN CARLOS LOPEZ MARTINEZ',
                'rfc' => 'LOMJ850214H12',
                'telefono' => '5551234567',
                'emailEnvio' => 'juan.lopez@example.com',
                'status' => 'A',
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();

        $service = new AspelService(
            $aspelPlatform,
            app(AuthStrategyResolver::class),
            $scheduleEvent,
            $record,
        );

        $result = $service->getUpdatedContacts([], $adapter);

        $this->assertFalse($result['success'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertNull(Config::query()->where('key', 'aspel.contacts.cursor.' . $scheduleEvent->id . '.since_ts')->first());
        $this->assertNull(Config::query()->where('key', 'aspel.contacts.cursor.' . $scheduleEvent->id . '.since_clave')->first());
        $this->assertSame('error', data_get(Config::query()->where('key', 'aspel.contacts.cursor.' . $scheduleEvent->id . '.last_run_status')->first()?->value, 'value'));

        $idempotency = EventIdempotencyKey::query()->where('event_id', $scheduleEvent->id)->first();
        $this->assertNotNull($idempotency);
        $this->assertSame('failed', $idempotency->status);
    }

    public function test_it_processes_multiple_pages_and_advances_cursor_after_last_page(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        [$aspelPlatform, $scheduleEvent, $record] = $this->preparePollingContext();

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts/search') {
                $filter = $request->data()['filterGroups'][0]['filters'][0] ?? [];
                $value = $filter['value'] ?? null;

                if ($value === '50902') {
                    return Http::response([
                        'results' => [[
                            'id' => '401',
                            'properties' => ['clave' => '50902'],
                        ]],
                    ], 200);
                }

                return Http::response(['results' => []], 200);
            }

            if ($request->method() === 'PATCH' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts/401') {
                return Http::response([
                    'id' => '401',
                    'properties' => $request->data()['properties'] ?? [],
                ], 200);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts') {
                return Http::response([
                    'id' => '502',
                    'properties' => $request->data()['properties'] ?? [],
                ], 201);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_changes_1',
            'external_id' => null,
            'latency_ms' => 10,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/api/contacts/changes',
            'method' => 'GET',
            'data' => [
                'items' => [[
                    'clave' => '50902',
                    'nombre' => 'JUAN CARLOS LOPEZ MARTINEZ',
                    'rfc' => 'LOMJ850214H12',
                    'status' => 'A',
                    'versionSinc' => '2026-05-07 10:00:00',
                ]],
                'nextSinceTs' => '2026-05-07 10:00:00',
                'nextSinceClave' => '50902',
                'hasMore' => true,
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_detail_1',
            'external_id' => '50902',
            'latency_ms' => 8,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/api/contacts/50902',
            'method' => 'GET',
            'data' => [
                'clave' => '50902',
                'nombre' => 'JUAN CARLOS LOPEZ MARTINEZ',
                'rfc' => 'LOMJ850214H12',
                'telefono' => '5551234567',
                'emailEnvio' => 'juan.lopez@example.com',
                'status' => 'A',
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_changes_2',
            'external_id' => null,
            'latency_ms' => 11,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/api/contacts/changes',
            'method' => 'GET',
            'data' => [
                'items' => [[
                    'clave' => '50903',
                    'nombre' => 'MARIA LOPEZ',
                    'rfc' => 'MALO900101ABC',
                    'status' => 'A',
                    'versionSinc' => '2026-05-07 10:05:00',
                ]],
                'nextSinceTs' => '2026-05-07 10:05:00',
                'nextSinceClave' => '50903',
                'hasMore' => false,
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_detail_2',
            'external_id' => '50903',
            'latency_ms' => 8,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/api/contacts/50903',
            'method' => 'GET',
            'data' => [
                'clave' => '50903',
                'nombre' => 'MARIA LOPEZ',
                'rfc' => 'MALO900101ABC',
                'telefono' => '5559876543',
                'emailEnvio' => 'maria.lopez@example.com',
                'status' => 'A',
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();

        $service = new AspelService(
            $aspelPlatform,
            app(AuthStrategyResolver::class),
            $scheduleEvent,
            $record,
        );

        $result = $service->getUpdatedContacts([], $adapter);

        $this->assertTrue($result['success'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame(2, data_get($result, 'data.metrics.pages_processed'));
        $this->assertSame(2, data_get($result, 'data.metrics.items_processed'));
        $this->assertSame('2026-05-07 10:05:00', data_get($result, 'data.cursor.sinceTs'));
        $this->assertSame('50903', data_get($result, 'data.cursor.sinceClave'));
    }

    public function test_it_does_not_advance_cursor_when_detail_fetch_returns_not_found(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        [$aspelPlatform, $scheduleEvent, $record] = $this->preparePollingContext();

        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_changes_1',
            'external_id' => null,
            'latency_ms' => 10,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/api/contacts/changes',
            'method' => 'GET',
            'data' => [
                'items' => [[
                    'clave' => '50902',
                    'nombre' => 'JUAN CARLOS LOPEZ MARTINEZ',
                    'rfc' => 'LOMJ850214H12',
                    'status' => 'A',
                    'versionSinc' => '2026-05-07 10:00:00',
                ]],
                'nextSinceTs' => '2026-05-07 10:00:00',
                'nextSinceClave' => '50902',
                'hasMore' => false,
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();
        $adapter->shouldReceive('send')->times(3)->andReturn(
            [
                'success' => false,
                'status_code' => 404,
                'retryable' => false,
                'request_id' => 'req_detail_1',
                'external_id' => '50902',
                'latency_ms' => 8,
                'attempt' => 1,
                'endpoint' => 'https://api.example.com/api/contacts/50902',
                'method' => 'GET',
                'data' => [],
                'error' => ['code' => 'not_found', 'message' => 'Contact not found', 'details' => ['clave' => '50902']],
            ]
        )->ordered();

        $service = new AspelService(
            $aspelPlatform,
            app(AuthStrategyResolver::class),
            $scheduleEvent,
            $record,
        );

        $result = $service->getUpdatedContacts([], $adapter);

        $this->assertFalse($result['success'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertNull(Config::query()->where('key', 'aspel.contacts.cursor.' . $scheduleEvent->id . '.since_ts')->first());
        $this->assertSame('error', data_get(Config::query()->where('key', 'aspel.contacts.cursor.' . $scheduleEvent->id . '.last_run_status')->first()?->value, 'value'));
        $this->assertSame('Failed to fetch ASPEL contact detail.', $result['message']);
        $this->assertSame(3, data_get($result, 'data.failure_context.detail_response.retry_attempts'));
    }

    public function test_it_processes_create_fallback_synchronously_during_polling(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        [$aspelPlatform, $scheduleEvent, $record] = $this->preparePollingContext();

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts/search') {
                return Http::response(['results' => []], 200);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts') {
                return Http::response([
                    'id' => '503',
                    'properties' => $request->data()['properties'] ?? [],
                ], 201);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_changes_1',
            'external_id' => null,
            'latency_ms' => 10,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/api/contacts/changes',
            'method' => 'GET',
            'data' => [
                'items' => [[
                    'clave' => '50902',
                    'nombre' => 'JUAN CARLOS LOPEZ MARTINEZ',
                    'rfc' => 'LOMJ850214H12',
                    'status' => 'A',
                    'versionSinc' => '2026-05-07 10:00:00',
                ]],
                'nextSinceTs' => '2026-05-07 10:00:00',
                'nextSinceClave' => '50902',
                'hasMore' => false,
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_detail_1',
            'external_id' => '50902',
            'latency_ms' => 8,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/api/contacts/50902',
            'method' => 'GET',
            'data' => [
                'clave' => '50902',
                'nombre' => 'JUAN CARLOS LOPEZ MARTINEZ',
                'rfc' => 'LOMJ850214H12',
                'telefono' => '5551234567',
                'emailEnvio' => 'juan.lopez@example.com',
                'status' => 'A',
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();

        $service = new AspelService(
            $aspelPlatform,
            app(AuthStrategyResolver::class),
            $scheduleEvent,
            $record,
        );

        $result = $service->getUpdatedContacts([], $adapter);

        $this->assertTrue($result['success'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertDatabaseHas('records', [
            'status' => 'success',
            'event_type' => 'object.created',
        ]);
    }

    private function preparePollingContext(): array
    {
        $hubspotPlatform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'credentials' => ['access_token' => 'token_123'],
            'active' => true,
        ]);

        $aspelPlatform = Platform::query()->create([
            'name' => 'ASPEL',
            'slug' => 'aspel',
            'type' => 'generic',
            'credentials' => ['api_key' => 'aspel-token-123'],
            'settings' => ['service_driver' => 'aspel'],
            'active' => true,
        ]);

        $mappingEvent = Event::query()->create([
            'platform_id' => $aspelPlatform->id,
            'name' => 'ASPEL Contacts Mapping',
            'event_type_id' => 'generic.external.call',
            'type' => 'schedule',
            'active' => true,
        ]);

        $hubspotCreateEvent = Event::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => 'Create ASPEL Contact In HubSpot',
            'event_type_id' => 'object.created',
            'method_name' => 'createAspelContactInHubspot',
            'type' => 'webhook',
            'meta' => [
                'object_type' => 'contacts',
                'target_platform' => 'aspel',
                'response_mapping_event_id' => $mappingEvent->id,
                'version_sinc_property' => 'version_sinc_aspel',
            ],
            'active' => true,
        ]);

        $hubspotEvent = Event::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => 'Sync ASPEL Contact To HubSpot',
            'event_type_id' => 'object.updated',
            'method_name' => 'updateAspelContactInHubspot',
            'to_event_id' => $hubspotCreateEvent->id,
            'type' => 'webhook',
            'meta' => [
                'object_type' => 'contacts',
                'target_platform' => 'aspel',
                'response_mapping_event_id' => $mappingEvent->id,
                'version_sinc_property' => 'version_sinc_aspel',
            ],
            'active' => true,
        ]);

        $scheduleEvent = Event::query()->create([
            'platform_id' => $aspelPlatform->id,
            'to_event_id' => $hubspotEvent->id,
            'name' => 'Poll ASPEL Contact Changes',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'getUpdatedContacts',
            'type' => 'schedule',
            'schedule_expression' => '*/5 * * * *',
            'active' => true,
        ]);

        EventHttpConfig::query()->create([
            'event_id' => $scheduleEvent->id,
            'method' => 'GET',
            'base_url' => 'https://api.example.com',
            'path' => '/api/contacts/changes',
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $scheduleEvent->id,
            'event_type' => 'generic.external.call',
            'status' => 'init',
            'payload' => [],
            'message' => 'init',
        ]);

        $hubspotProperties = [
            'firstname' => 'Nombre',
            'email' => 'Correo',
            'phone' => 'Telefono',
            'rfc' => 'RFC',
            'clave' => 'Clave',
            'version_sinc_aspel' => 'Version sinc ASPEL',
        ];

        $aspelProperties = [
            'nombre' => 'Nombre',
            'emailEnvio' => 'Correo',
            'telefono' => 'Telefono',
            'rfc' => 'RFC',
            'clave' => 'Clave',
        ];

        $createdHubspotProperties = [];
        foreach ($hubspotProperties as $key => $name) {
            $createdHubspotProperties[$key] = Property::query()->create([
                'platform_id' => $hubspotPlatform->id,
                'name' => $name,
                'key' => $key,
                'type' => 'string',
                'active' => true,
            ]);
        }

        $createdAspelProperties = [];
        foreach ($aspelProperties as $key => $name) {
            $createdAspelProperties[$key] = Property::query()->create([
                'platform_id' => $aspelPlatform->id,
                'name' => $name,
                'key' => $key,
                'type' => 'string',
                'active' => true,
            ]);
        }

        foreach ([
            'firstname' => 'nombre',
            'email' => 'emailEnvio',
            'phone' => 'telefono',
            'rfc' => 'rfc',
            'clave' => 'clave',
        ] as $hubspotKey => $aspelKey) {
            PropertyRelationship::query()->create([
                'event_id' => $mappingEvent->id,
                'property_id' => $createdHubspotProperties[$hubspotKey]->id,
                'related_property_id' => $createdAspelProperties[$aspelKey]->id,
                'active' => true,
            ]);
        }

        return [$aspelPlatform, $scheduleEvent, $record];
    }
}

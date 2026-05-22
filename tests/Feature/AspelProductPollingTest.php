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

class AspelProductPollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_processes_changed_products_and_persists_cursor_on_success(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        [$aspelPlatform, $scheduleEvent, $record] = $this->preparePollingContext();

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/products/search') {
                return Http::response([
                    'results' => [[
                        'id' => '901',
                        'properties' => ['clave' => 'A001'],
                    ]],
                ], 200);
            }

            if ($request->method() === 'PATCH' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/products/901') {
                return Http::response([
                    'id' => '901',
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
            'request_id' => 'req_product_changes_1',
            'external_id' => null,
            'latency_ms' => 10,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/api/products/changes',
            'method' => 'GET',
            'data' => [
                'items' => [[
                    'clave' => 'A001',
                    'descripcion' => 'Producto demo',
                    'linea' => '001',
                    'claveSat' => '10101500',
                    'claveUnidad' => 'H87',
                    'clase' => 'P',
                    'unidadEntrada' => 'PZA',
                    'unidadSalida' => 'PZA',
                    'versionSinc' => '2026-05-21T10:15:30',
                ]],
                'nextSinceTs' => '2026-05-21T10:15:30',
                'nextSinceClave' => 'A001',
                'hasMore' => false,
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_product_detail_1',
            'external_id' => 'A001',
            'latency_ms' => 8,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/api/products/A001',
            'method' => 'GET',
            'data' => [
                'clave' => 'A001',
                'descripcion' => 'Producto demo',
                'linea' => '001',
                'claveSat' => '10101500',
                'claveUnidad' => 'H87',
                'clase' => 'P',
                'unidadEntrada' => 'PZA',
                'unidadSalida' => 'PZA',
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();

        $service = new AspelService(
            $aspelPlatform,
            app(AuthStrategyResolver::class),
            $scheduleEvent,
            $record,
        );

        $result = $service->getUpdatedProducts([], $adapter);

        $this->assertTrue($result['success'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame('2026-05-21T10:15:30', data_get($result, 'data.cursor.sinceTs'));
        $this->assertSame('A001', data_get($result, 'data.cursor.sinceClave'));
        $this->assertSame('success', data_get(Config::query()->where('key', 'aspel.products.cursor.' . $scheduleEvent->id . '.last_run_status')->first()?->value, 'value'));
        $this->assertSame('2026-05-21T10:15:30', data_get(Config::query()->where('key', 'aspel.products.cursor.' . $scheduleEvent->id . '.since_ts')->first()?->value, 'value'));
        $this->assertSame('A001', data_get(Config::query()->where('key', 'aspel.products.cursor.' . $scheduleEvent->id . '.since_clave')->first()?->value, 'value'));

        $this->assertDatabaseHas('event_idempotency_keys', [
            'event_id' => $scheduleEvent->id,
            'status' => 'success',
        ]);
    }

    public function test_it_uses_create_fallback_when_product_does_not_exist_in_hubspot(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        [$aspelPlatform, $scheduleEvent, $record] = $this->preparePollingContext();

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/products/search') {
                return Http::response(['results' => []], 200);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/products') {
                return Http::response([
                    'id' => '902',
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
            'request_id' => 'req_product_changes_1',
            'external_id' => null,
            'latency_ms' => 10,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/api/products/changes',
            'method' => 'GET',
            'data' => [
                'items' => [[
                    'clave' => 'A001',
                    'descripcion' => 'Producto demo',
                    'linea' => '001',
                    'claveSat' => '10101500',
                    'claveUnidad' => 'H87',
                    'clase' => 'P',
                    'unidadEntrada' => 'PZA',
                    'unidadSalida' => 'PZA',
                    'versionSinc' => '2026-05-21T10:15:30',
                ]],
                'nextSinceTs' => '2026-05-21T10:15:30',
                'nextSinceClave' => 'A001',
                'hasMore' => false,
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_product_detail_1',
            'external_id' => 'A001',
            'latency_ms' => 8,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/api/products/A001',
            'method' => 'GET',
            'data' => [
                'clave' => 'A001',
                'descripcion' => 'Producto demo',
                'linea' => '001',
                'claveSat' => '10101500',
                'claveUnidad' => 'H87',
                'clase' => 'P',
                'unidadEntrada' => 'PZA',
                'unidadSalida' => 'PZA',
            ],
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ])->ordered();

        $service = new AspelService(
            $aspelPlatform,
            app(AuthStrategyResolver::class),
            $scheduleEvent,
            $record,
        );

        $result = $service->getUpdatedProducts([], $adapter);

        $this->assertTrue($result['success'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame('A001', data_get(Config::query()->where('key', 'aspel.products.cursor.' . $scheduleEvent->id . '.since_clave')->first()?->value, 'value'));
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
            'name' => 'ASPEL Products Mapping',
            'event_type_id' => 'generic.external.call',
            'type' => 'schedule',
            'active' => true,
        ]);

        $createEvent = Event::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => 'Create ASPEL Product To HubSpot',
            'event_type_id' => 'product.created',
            'method_name' => 'createAspelProductInHubspot',
            'type' => 'webhook',
            'meta' => [
                'object_type' => 'products',
                'mapping_event_id' => $mappingEvent->id,
            ],
            'active' => true,
        ]);

        $updateEvent = Event::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => 'Sync ASPEL Product To HubSpot',
            'event_type_id' => 'product.updated',
            'method_name' => 'updateAspelProductInHubspot',
            'to_event_id' => $createEvent->id,
            'type' => 'webhook',
            'meta' => [
                'object_type' => 'products',
                'mapping_event_id' => $mappingEvent->id,
            ],
            'active' => true,
        ]);

        $scheduleEvent = Event::query()->create([
            'platform_id' => $aspelPlatform->id,
            'name' => 'Polling Productos ASPEL a HubSpot',
            'event_type_id' => 'generic.external.call',
            'type' => 'schedule',
            'method_name' => 'getUpdatedProducts',
            'to_event_id' => $updateEvent->id,
            'meta' => [
                'take' => 200,
                'object_type' => 'products',
                'source_platform' => 'aspel',
                'target_platform' => 'hubspot',
                'initial_lookback_hours' => 24,
            ],
            'active' => true,
        ]);

        EventHttpConfig::query()->create([
            'event_id' => $scheduleEvent->id,
            'method' => 'GET',
            'base_url' => 'https://api.example.com',
            'path' => '/api/products/changes',
            'headers_json' => [],
            'query_json' => [],
            'auth_config_json' => [],
            'retry_policy_json' => [],
            'idempotency_config_json' => [],
            'allowlist_domains_json' => [],
            'timeout_seconds' => 30,
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
            'clave' => 'Clave',
            'name' => 'Nombre',
            'linea_sae' => 'Linea',
            'clave_sat_sae' => 'Clave SAT',
            'clave_unidad_sae' => 'Clave Unidad',
            'tipo_sae' => 'Tipo',
            'unidad_de_entrada_sae' => 'Unidad Entrada',
            'unidad_de_salida_sae' => 'Unidad Salida',
        ];

        $aspelProperties = [
            'clave' => 'Clave',
            'descripcion' => 'Descripcion',
            'linea' => 'Linea',
            'claveSat' => 'Clave SAT',
            'claveUnidad' => 'Clave Unidad',
            'clase' => 'Clase',
            'unidadEntrada' => 'Unidad Entrada',
            'unidadSalida' => 'Unidad Salida',
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
            'clave' => 'clave',
            'name' => 'descripcion',
            'linea_sae' => 'linea',
            'clave_sat_sae' => 'claveSat',
            'clave_unidad_sae' => 'claveUnidad',
            'tipo_sae' => 'clase',
            'unidad_de_entrada_sae' => 'unidadEntrada',
            'unidad_de_salida_sae' => 'unidadSalida',
        ] as $hubspotKey => $aspelKey) {
            PropertyRelationship::query()->create([
                'event_id' => $mappingEvent->id,
                'property_id' => $createdHubspotProperties[$hubspotKey]->id,
                'related_property_id' => $createdAspelProperties[$aspelKey]->id,
                'active' => true,
                'meta' => [],
            ]);
        }

        return [$aspelPlatform, $scheduleEvent, $record];
    }
}

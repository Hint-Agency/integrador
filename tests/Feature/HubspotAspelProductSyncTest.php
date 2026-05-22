<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use App\Models\Record;
use App\Services\Hubspot\HubspotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubspotAspelProductSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_hubspot_product_matching_by_clave(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

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

        [$hubspotPlatform, $syncEvent, $record] = $this->prepareAspelHubspotProductSyncContext();

        $service = app()->make(HubspotService::class, [
            'platform' => $hubspotPlatform,
            'event' => $syncEvent,
            'record' => $record,
        ]);

        $result = $service->updateAspelProductInHubspot($this->buildAspelProductPayload());

        $this->assertTrue($result['success']);
        $this->assertSame('updated', $result['data']['operation']);
        $this->assertSame('901', $result['data']['product_id']);
        $this->assertSame('clave', $result['data']['matched_by']);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PATCH' || $request->url() !== 'https://api.hubapi.test/crm/v3/objects/products/901') {
                return false;
            }

            $properties = $request->data()['properties'] ?? [];

            return ($properties['clave'] ?? null) === 'A001'
                && ($properties['name'] ?? null) === 'Producto demo'
                && ($properties['linea_sae'] ?? null) === '001'
                && ($properties['clave_sat_sae'] ?? null) === '10101500'
                && ($properties['clave_unidad_sae'] ?? null) === 'H87'
                && ($properties['tipo_sae'] ?? null) === 'P'
                && ($properties['unidad_de_entrada_sae'] ?? null) === 'PZA'
                && ($properties['unidad_de_salida_sae'] ?? null) === 'PZA';
        });
    }

    public function test_it_prepares_product_create_fallback_when_no_hubspot_match_is_found(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        Http::fake(fn () => Http::response(['results' => []], 200));

        [$hubspotPlatform, $syncEvent, $record] = $this->prepareAspelHubspotProductSyncContext();

        $service = app()->make(HubspotService::class, [
            'platform' => $hubspotPlatform,
            'event' => $syncEvent,
            'record' => $record,
        ]);

        $result = $service->updateAspelProductInHubspot($this->buildAspelProductPayload());

        $this->assertTrue($result['success']);
        $this->assertSame('not_found', $result['data']['operation']);
        $this->assertSame(1, $result['data']['not_found_count']);
        $this->assertSame($this->buildAspelProductPayload(), $result['data']['output_payload']);
    }

    public function test_it_creates_hubspot_product_from_explicit_create_fallback(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/products') {
                return Http::response([
                    'id' => '902',
                    'properties' => $request->data()['properties'] ?? [],
                ], 201);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        [$hubspotPlatform, $syncEvent, $record] = $this->prepareAspelHubspotProductSyncContext();

        $service = app()->make(HubspotService::class, [
            'platform' => $hubspotPlatform,
            'event' => $syncEvent,
            'record' => $record,
        ]);

        $result = $service->createAspelProductInHubspot($this->buildAspelProductPayload());

        $this->assertTrue($result['success']);
        $this->assertSame('created', $result['data']['operation']);
        $this->assertSame('902', $result['data']['product_id']);
    }

    private function prepareAspelHubspotProductSyncContext(): array
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

        $syncEvent = Event::query()->create([
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

        $record = Record::query()->create([
            'event_id' => $syncEvent->id,
            'event_type' => 'product.updated',
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

        return [$hubspotPlatform, $syncEvent, $record];
    }

    private function buildAspelProductPayload(): array
    {
        return [
            'source_platform' => 'aspel',
            'source_event_id' => 999,
            'clave' => 'A001',
            'versionSinc' => '2026-05-21T10:15:30',
            'aspel_detail' => [
                'clave' => 'A001',
                'descripcion' => 'Producto demo',
                'linea' => '001',
                'claveSat' => '10101500',
                'claveUnidad' => 'H87',
                'clase' => 'P',
                'unidadEntrada' => 'PZA',
                'unidadSalida' => 'PZA',
            ],
        ];
    }
}

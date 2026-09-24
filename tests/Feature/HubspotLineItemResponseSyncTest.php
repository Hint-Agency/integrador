<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use App\Models\Record;
use App\Services\Hubspot\HubspotService;
use Database\Seeders\AspelWarehouseMappingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubspotLineItemResponseSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_hubspot_line_item_from_destination_response(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        Http::fake([
            'https://api.hubapi.test/crm/v3/objects/line_items/line_1' => Http::response([
                'id' => 'line_1',
                'properties' => [
                    'existencias' => '17.5',
                    'stock_maximo' => '150',
                    'stock_minimo' => '15',
                ],
            ], 200),
        ]);

        $hubspotPlatform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'credentials' => ['access_token' => 'token_123'],
            'active' => true,
        ]);

        $writebackEvent = Event::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => 'Actualizar line item HubSpot con respuesta destino',
            'event_type_id' => 'object.updated',
            'method_name' => 'syncLineItemExecutionResponse',
            'type' => 'webhook',
            'meta' => [
                'object_type' => 'line_items',
                'target_platform' => 'aspel',
            ],
            'active' => true,
        ]);

        $aspel = Platform::query()->create([
            'name' => 'ASPEL', 'slug' => 'aspel', 'type' => 'generic', 'active' => true,
        ]);
        Event::query()->create([
            'platform_id' => $aspel->id, 'name' => 'Warehouse lookup',
            'event_type_id' => 'generic.external.call', 'method_name' => 'syncLineItemWarehouseInventory',
            'type' => 'webhook', 'to_event_id' => $writebackEvent->id, 'active' => true,
        ]);
        $this->seed(AspelWarehouseMappingsSeeder::class);
        $this->seed(AspelWarehouseMappingsSeeder::class);
        $this->assertSame(3, $writebackEvent->propertyRelationships()->count());

        $record = Record::query()->create([
            'event_id' => $writebackEvent->id,
            'event_type' => 'object.updated',
            'status' => 'init',
            'payload' => [],
            'message' => 'init',
        ]);

        $service = app()->make(HubspotService::class, [
            'platform' => $hubspotPlatform,
            'event' => $writebackEvent,
            'record' => $record,
        ]);

        $result = $service->syncLineItemExecutionResponse([
            'hubspot_object_id' => 'line_1',
            'destination_response' => [
                'data' => [
                    'existencias' => 17.5,
                    'stock_maximo' => 150,
                    'stock_minimo' => 15,
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('line_1', $result['data']['line_item_id']);
        $this->assertSame(17.5, $result['data']['updated_properties']['existencias']);
        $this->assertSame(150, $result['data']['updated_properties']['stock_maximo']);
        $this->assertSame(15, $result['data']['updated_properties']['stock_minimo']);

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PATCH' || $request->url() !== 'https://api.hubapi.test/crm/v3/objects/line_items/line_1') {
                return false;
            }

            $properties = $request->data()['properties'] ?? [];

            return ($properties['existencias'] ?? null) === 17.5
                && ($properties['stock_maximo'] ?? null) === 150
                && ($properties['stock_minimo'] ?? null) === 15;
        });
    }

    public function test_it_maps_extra_response_fields_defaults_and_constants_without_writing_context(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');
        Http::fake(fn () => Http::response(['id' => 'line_1'], 200));
        [$service, $event, $platform, $record] = $this->prepareContext();

        foreach ([
            ['disponibilidad_sae', 'destination_response.data.warehouse_records.0.exist', ['transform' => 'decimal'], true],
            ['stock_minimo', 'missing_stock', ['default_value' => 0, 'transform' => 'decimal'], true],
            ['estado_consulta', null, ['mode' => 'constant', 'value' => 'consultado'], true],
            ['stock_maximo', 'stock_maximo', [], false],
            ['hs_object_id', null, ['mode' => 'constant', 'value' => 'wrong_id'], true],
            ['not_from_context', 'hubspot_object_id', [], true],
        ] as [$key, $path, $meta, $active]) {
            $target = Property::query()->create([
                'platform_id' => $platform->id, 'name' => $key, 'key' => $key,
                'type' => 'number', 'active' => true,
            ]);
            PropertyRelationship::query()->create([
                'event_id' => $event->id, 'property_id' => null,
                'related_property_id' => $target->id, 'mapping_key' => $path,
                'meta' => $meta, 'active' => $active,
            ]);
        }

        $result = $service->syncLineItemExecutionResponse([
            'hubspot_object_id' => 'line_1',
            'destination_response' => ['data' => [
                'warehouse_records' => [['exist' => 12.5]],
                'stock_maximo' => 100, 'unmapped' => 'must_not_write',
            ]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame([
            'disponibilidad_sae' => 12.5, 'stock_minimo' => 0.0, 'estado_consulta' => 'consultado',
        ], $result['data']['updated_properties']);
        $this->assertSame('stock_minimo', $record->fresh()->details['mapping_defaults_applied'][0]['destination_property']);
        Http::assertSentCount(1);
    }

    public function test_it_warns_without_http_when_no_mapping_is_configured(): void
    {
        Http::fake();
        [$service] = $this->prepareContext();
        $result = $service->syncLineItemExecutionResponse([
            'hubspot_object_id' => 'line_1',
            'destination_response' => ['data' => ['existencias' => 100]],
        ]);
        $this->assertSame('warning', $result['status']);
        $this->assertSame('no_mapped_line_item_properties', $result['data']['reason']);
        Http::assertNothingSent();
    }

    private function prepareContext(): array
    {
        $platform = Platform::query()->create([
            'name' => 'HubSpot', 'slug' => 'hubspot', 'type' => 'hubspot',
            'credentials' => ['access_token' => 'token_123'], 'active' => true,
        ]);
        $event = Event::query()->create([
            'platform_id' => $platform->id, 'name' => 'Warehouse write-back',
            'event_type_id' => 'object.updated', 'method_name' => 'syncLineItemExecutionResponse',
            'type' => 'webhook', 'meta' => ['object_type' => 'line_items'], 'active' => true,
        ]);
        $record = Record::query()->create([
            'event_id' => $event->id, 'event_type' => 'object.updated', 'status' => 'init',
            'payload' => [], 'message' => 'init',
        ]);
        $service = app()->make(HubspotService::class, [
            'platform' => $platform, 'event' => $event, 'record' => $record,
        ]);

        return [$service, $event, $platform, $record];
    }
}

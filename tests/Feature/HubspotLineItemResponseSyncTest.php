<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Platform;
use App\Models\Record;
use App\Services\Hubspot\HubspotService;
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
}

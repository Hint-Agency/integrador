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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubspotAspelContactSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_hubspot_contact_matching_by_rfc_when_clave_is_missing_in_hubspot(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts/search') {
                $filter = $request->data()['filterGroups'][0]['filters'][0] ?? [];
                $propertyName = $filter['propertyName'] ?? null;
                $value = $filter['value'] ?? null;

                if ($propertyName === 'clave' && $value === '50902') {
                    return Http::response(['results' => []], 200);
                }

                if ($propertyName === 'rfc' && $value === 'LOMJ850214H12') {
                    return Http::response([
                        'results' => [[
                            'id' => '401',
                            'properties' => [
                                'rfc' => 'LOMJ850214H12',
                            ],
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

        [$hubspotPlatform, $aspelPlatform, $mappingEvent, $syncEvent, $record] = $this->prepareAspelHubspotSyncContext();

        $service = app()->make(HubspotService::class, [
            'platform' => $hubspotPlatform,
            'event' => $syncEvent,
            'record' => $record,
        ]);

        $result = $service->updateAspelContactInHubspot($this->buildAspelPayload());

        $this->assertTrue($result['success']);
        $this->assertSame('updated', $result['data']['operation']);
        $this->assertSame('401', $result['data']['contact_id']);
        $this->assertSame('rfc', $result['data']['matched_by']);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PATCH' || $request->url() !== 'https://api.hubapi.test/crm/v3/objects/contacts/401') {
                return false;
            }

            $properties = $request->data()['properties'] ?? [];

            return ($properties['firstname'] ?? null) === 'JUAN CARLOS LOPEZ MARTINEZ'
                && ($properties['email'] ?? null) === 'juan.lopez@example.com'
                && ($properties['phone'] ?? null) === '5551234567'
                && ($properties['rfc'] ?? null) === 'LOMJ850214H12'
                && ($properties['clave'] ?? null) === '50902'
                && ($properties['fecha_alta'] ?? null) === Carbon::parse('2026-09-01T00:00:00')->utc()->getTimestampMs()
                && ($properties['sync_status_aspel'] ?? null) === 'success'
                && ($properties['last_error_aspel'] ?? null) === ''
                && array_key_exists('last_sync_aspel', $properties)
                && ($properties['version_sinc_aspel'] ?? null) === '2026-05-07 10:00:00';
        });
    }

    public function test_it_prepares_create_fallback_when_no_hubspot_match_is_found(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts/search') {
                return Http::response(['results' => []], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        [$hubspotPlatform, $aspelPlatform, $mappingEvent, $syncEvent, $record] = $this->prepareAspelHubspotSyncContext();

        $service = app()->make(HubspotService::class, [
            'platform' => $hubspotPlatform,
            'event' => $syncEvent,
            'record' => $record,
        ]);

        $result = $service->updateAspelContactInHubspot($this->buildAspelPayload());

        $this->assertTrue($result['success']);
        $this->assertSame('not_found', $result['data']['operation']);
        $this->assertSame(1, $result['data']['not_found_count']);
        $this->assertSame($this->buildAspelPayload(), $result['data']['output_payload']);
    }

    public function test_it_updates_hubspot_contact_matching_by_phone_when_clave_and_rfc_do_not_match(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts/search') {
                $filter = $request->data()['filterGroups'][0]['filters'][0] ?? [];
                $propertyName = $filter['propertyName'] ?? null;
                $value = $filter['value'] ?? null;

                if ($propertyName === 'clave' && $value === '50902') {
                    return Http::response(['results' => []], 200);
                }

                if ($propertyName === 'rfc' && $value === 'LOMJ850214H12') {
                    return Http::response(['results' => []], 200);
                }

                if ($propertyName === 'phone' && $value === '5551234567') {
                    return Http::response([
                        'results' => [[
                            'id' => '402',
                            'properties' => [
                                'phone' => '5551234567',
                            ],
                        ]],
                    ], 200);
                }
            }

            if ($request->method() === 'PATCH' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts/402') {
                return Http::response([
                    'id' => '402',
                    'properties' => $request->data()['properties'] ?? [],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        [$hubspotPlatform, $aspelPlatform, $mappingEvent, $syncEvent, $record] = $this->prepareAspelHubspotSyncContext();

        $service = app()->make(HubspotService::class, [
            'platform' => $hubspotPlatform,
            'event' => $syncEvent,
            'record' => $record,
        ]);

        $result = $service->updateAspelContactInHubspot($this->buildAspelPayload());

        $this->assertTrue($result['success']);
        $this->assertSame('updated', $result['data']['operation']);
        $this->assertSame('402', $result['data']['contact_id']);
        $this->assertSame('phone', $result['data']['matched_by']);
    }

    public function test_it_returns_error_when_multiple_hubspot_contacts_match_same_aspel_value(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts/search') {
                $filter = $request->data()['filterGroups'][0]['filters'][0] ?? [];
                $propertyName = $filter['propertyName'] ?? null;
                $value = $filter['value'] ?? null;

                if ($propertyName === 'clave' && $value === '50902') {
                    return Http::response([
                        'results' => [
                            ['id' => '401', 'properties' => ['clave' => '50902']],
                            ['id' => '402', 'properties' => ['clave' => '50902']],
                        ],
                    ], 200);
                }
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        [$hubspotPlatform, $aspelPlatform, $mappingEvent, $syncEvent, $record] = $this->prepareAspelHubspotSyncContext();

        $service = app()->make(HubspotService::class, [
            'platform' => $hubspotPlatform,
            'event' => $syncEvent,
            'record' => $record,
        ]);

        $result = $service->updateAspelContactInHubspot($this->buildAspelPayload());

        $this->assertTrue($result['success']);
        $this->assertSame('warning', $result['status']);
        $this->assertSame('Multiple HubSpot contacts matched ASPEL change.', $result['message']);
        $this->assertSame('clave', data_get($result, 'data.match_property'));
        $this->assertCount(2, data_get($result, 'data.matches', []));
    }

    public function test_it_creates_hubspot_contact_from_explicit_create_fallback(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts') {
                return Http::response([
                    'id' => '501',
                    'properties' => $request->data()['properties'] ?? [],
                ], 201);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        [$hubspotPlatform, $aspelPlatform, $mappingEvent, $syncEvent, $record] = $this->prepareAspelHubspotSyncContext();

        $service = app()->make(HubspotService::class, [
            'platform' => $hubspotPlatform,
            'event' => $syncEvent,
            'record' => $record,
        ]);

        $result = $service->createAspelContactInHubspot($this->buildAspelPayload());

        $this->assertTrue($result['success']);
        $this->assertSame('created', $result['data']['operation']);
        $this->assertSame('501', $result['data']['contact_id']);
    }

    private function prepareAspelHubspotSyncContext(): array
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
            'name' => 'ASPEL Contacts Mapping',
            'event_type_id' => 'generic.external.call',
            'type' => 'schedule',
            'active' => true,
        ]);

        $createEvent = Event::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => 'Create ASPEL Contact To HubSpot',
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

        $syncEvent = Event::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => 'Sync ASPEL Contact To HubSpot',
            'event_type_id' => 'object.updated',
            'method_name' => 'updateAspelContactInHubspot',
            'to_event_id' => $createEvent->id,
            'type' => 'webhook',
            'meta' => [
                'object_type' => 'contacts',
                'target_platform' => 'aspel',
                'response_mapping_event_id' => $mappingEvent->id,
                'version_sinc_property' => 'version_sinc_aspel',
            ],
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $syncEvent->id,
            'event_type' => 'object.updated',
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
            'fecha_alta' => 'Fecha alta',
            'version_sinc_aspel' => 'Version sinc ASPEL',
        ];

        $aspelProperties = [
            'nombre' => 'Nombre',
            'emailEnvio' => 'Correo',
            'telefono' => 'Telefono',
            'rfc' => 'RFC',
            'clave' => 'Clave',
            'fechaAlta' => 'Fecha alta',
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

        PropertyRelationship::query()->create([
            'event_id' => $mappingEvent->id,
            'property_id' => $createdHubspotProperties['fecha_alta']->id,
            'related_property_id' => $createdAspelProperties['fechaAlta']->id,
            'meta' => [
                'transform' => 'hubspot_datetime_to_millis',
            ],
            'active' => true,
        ]);

        return [$hubspotPlatform, $aspelPlatform, $mappingEvent, $syncEvent, $record];
    }

    private function buildAspelPayload(): array
    {
        return [
            'source_platform' => 'aspel',
            'source_event_id' => 900,
            'clave' => '50902',
            'rfc' => 'LOMJ850214H12',
            'status' => 'A',
            'versionSinc' => '2026-05-07 10:00:00',
            'phone' => '5551234567',
            'email' => 'juan.lopez@example.com',
            'aspel_detail' => [
                'clave' => '50902',
                'nombre' => 'JUAN CARLOS LOPEZ MARTINEZ',
                'rfc' => 'LOMJ850214H12',
                'telefono' => '5551234567',
                'emailEnvio' => 'juan.lopez@example.com',
                'fechaAlta' => '2026-09-01T00:00:00',
                'status' => 'A',
            ],
        ];
    }
}

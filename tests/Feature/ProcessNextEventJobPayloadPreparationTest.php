<?php

namespace Tests\Feature;

use App\Jobs\ProcessNextEventJob;
use App\Models\Event;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use App\Models\Record;
use App\Services\EventFlowService;
use App\Services\EventProcessingService;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProcessNextEventJobPayloadPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_applies_property_relationship_mapping_before_dispatching_next_event(): void
    {
        $sourcePlatform = Platform::query()->create([
            'name' => 'Source',
            'slug' => 'source',
            'type' => 'generic',
            'active' => true,
        ]);
        $targetPlatform = Platform::query()->create([
            'name' => 'Target',
            'slug' => 'target',
            'type' => 'odoo',
            'active' => true,
        ]);

        $nextEvent = Event::query()->create([
            'platform_id' => $targetPlatform->id,
            'name' => 'Target Event',
            'event_type_id' => 'company.create',
            'type' => 'webhook',
            'active' => true,
        ]);
        $sourceEvent = Event::query()->create([
            'platform_id' => $sourcePlatform->id,
            'to_event_id' => $nextEvent->id,
            'name' => 'Source Event',
            'event_type_id' => 'source.webhook',
            'type' => 'webhook',
            'active' => true,
        ]);

        $sourceProperty = Property::query()->create([
            'platform_id' => $sourcePlatform->id,
            'name' => 'Email',
            'key' => 'email',
            'type' => 'string',
            'active' => true,
        ]);
        $targetProperty = Property::query()->create([
            'platform_id' => $targetPlatform->id,
            'name' => 'Partner Email',
            'key' => 'partner_email',
            'type' => 'string',
            'active' => true,
        ]);

        PropertyRelationship::query()->create([
            'event_id' => $sourceEvent->id,
            'property_id' => $sourceProperty->id,
            'related_property_id' => $targetProperty->id,
            'mapping_key' => 'email',
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $sourceEvent->id,
            'event_type' => 'source.webhook',
            'status' => 'init',
            'payload' => ['email' => 'ana@example.com'],
            'message' => 'init',
        ]);

        $eventProcessing = Mockery::mock(EventProcessingService::class);
        $eventProcessing->shouldReceive('dispatchEvent')
            ->once()
            ->withArgs(function (Event $event, Record $jobRecord, array $payload) use ($nextEvent, $record): bool {
                return $event->id === $nextEvent->id
                    && $jobRecord->id === $record->id
                    && ($payload['partner_email'] ?? null) === 'ana@example.com';
            });

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldNotReceive('getObject');

        $job = new ProcessNextEventJob($sourceEvent, $record, ['email' => 'ana@example.com']);
        $job->handle($eventProcessing, app(EventFlowService::class), $hubspotApi);

        $record->refresh();
        $this->assertSame('ana@example.com', data_get($record->details, 'output_payload.partner_email'));
        $this->assertSame($nextEvent->id, data_get($record->details, 'next_event.id'));
    }

    public function test_it_maps_catalog_value_to_key_before_dispatching_next_event(): void
    {
        $odoo = Platform::query()->create([
            'name' => 'Odoo',
            'slug' => 'odoo-products',
            'type' => 'odoo',
            'active' => true,
            'settings' => [
                'odoo' => [
                    'catalogs' => [
                        'uom' => [
                            'Día(s)' => 6,
                            'Hora(s)' => 5,
                            'Unidad de servicio' => 20,
                            'Unidad(es)' => 1,
                        ],
                    ],
                ],
            ],
        ]);
        $hubspot = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot-products',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $nextEvent = Event::query()->create([
            'platform_id' => $hubspot->id,
            'name' => 'Update Product',
            'event_type_id' => 'product.updated',
            'type' => 'node',
            'active' => true,
        ]);
        $sourceEvent = Event::query()->create([
            'platform_id' => $odoo->id,
            'to_event_id' => $nextEvent->id,
            'name' => 'Sync Source Products',
            'event_type_id' => 'product.updated',
            'type' => 'schedule',
            'active' => true,
        ]);

        $sourceProperty = Property::query()->create([
            'platform_id' => $odoo->id,
            'name' => 'UOM ID',
            'key' => 'uom_id.0',
            'type' => 'integer',
            'active' => true,
        ]);
        $targetProperty = Property::query()->create([
            'platform_id' => $hubspot->id,
            'name' => 'Unidad de medida',
            'key' => 'unidad_de_medida',
            'type' => 'string',
            'active' => true,
        ]);

        PropertyRelationship::query()->create([
            'event_id' => $sourceEvent->id,
            'property_id' => $sourceProperty->id,
            'related_property_id' => $targetProperty->id,
            'mapping_key' => 'uom_id.0',
            'active' => true,
            'meta' => [
                'catalog' => [
                    'platform' => 'source',
                    'path' => 'odoo.catalogs.uom',
                    'match' => 'value',
                    'output' => 'key',
                ],
            ],
        ]);

        $record = Record::query()->create([
            'event_id' => $sourceEvent->id,
            'event_type' => 'product.updated',
            'status' => 'init',
            'payload' => [],
            'message' => 'init',
        ]);

        $eventProcessing = Mockery::mock(EventProcessingService::class);
        $eventProcessing->shouldReceive('dispatchEvent')
            ->once()
            ->withArgs(fn (Event $event, Record $jobRecord, array $payload): bool => $event->id === $nextEvent->id
                && $jobRecord->id === $record->id
                && ($payload['unidad_de_medida'] ?? null) === 'Unidad de servicio');

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldNotReceive('getObject');

        $job = new ProcessNextEventJob($sourceEvent, $record, [
            'uom_id' => [20, 'Unidad de Servicio'],
        ]);
        $job->handle($eventProcessing, app(EventFlowService::class), $hubspotApi);

        $record->refresh();
        $this->assertSame('Unidad de servicio', data_get($record->details, 'output_payload.unidad_de_medida'));
    }

    public function test_it_omits_catalog_mapping_when_value_is_unknown(): void
    {
        $odoo = Platform::query()->create([
            'name' => 'Odoo',
            'slug' => 'odoo-products-unknown-uom',
            'type' => 'odoo',
            'active' => true,
            'settings' => [
                'odoo' => [
                    'catalogs' => [
                        'uom' => [
                            'Unidad de servicio' => 20,
                        ],
                    ],
                ],
            ],
        ]);
        $hubspot = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot-products-unknown-uom',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $nextEvent = Event::query()->create([
            'platform_id' => $hubspot->id,
            'name' => 'Update Product',
            'event_type_id' => 'product.updated',
            'type' => 'node',
            'active' => true,
        ]);
        $sourceEvent = Event::query()->create([
            'platform_id' => $odoo->id,
            'to_event_id' => $nextEvent->id,
            'name' => 'Sync Source Products',
            'event_type_id' => 'product.updated',
            'type' => 'schedule',
            'active' => true,
        ]);

        $sourceProperty = Property::query()->create([
            'platform_id' => $odoo->id,
            'name' => 'UOM ID',
            'key' => 'uom_id.0',
            'type' => 'integer',
            'active' => true,
        ]);
        $targetProperty = Property::query()->create([
            'platform_id' => $hubspot->id,
            'name' => 'Unidad de medida',
            'key' => 'unidad_de_medida',
            'type' => 'string',
            'active' => true,
        ]);

        PropertyRelationship::query()->create([
            'event_id' => $sourceEvent->id,
            'property_id' => $sourceProperty->id,
            'related_property_id' => $targetProperty->id,
            'mapping_key' => 'uom_id.0',
            'active' => true,
            'meta' => [
                'catalog' => [
                    'platform' => 'source',
                    'path' => 'odoo.catalogs.uom',
                    'match' => 'value',
                    'output' => 'key',
                ],
            ],
        ]);

        $record = Record::query()->create([
            'event_id' => $sourceEvent->id,
            'event_type' => 'product.updated',
            'status' => 'init',
            'payload' => [],
            'message' => 'init',
        ]);

        $eventProcessing = Mockery::mock(EventProcessingService::class);
        $eventProcessing->shouldReceive('dispatchEvent')
            ->once()
            ->withArgs(fn (Event $event, Record $jobRecord, array $payload): bool => $event->id === $nextEvent->id
                && $jobRecord->id === $record->id
                && ! array_key_exists('unidad_de_medida', $payload));

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldNotReceive('getObject');

        $job = new ProcessNextEventJob($sourceEvent, $record, [
            'uom_id' => [999, 'Desconocida'],
            'unidad_de_medida' => 'Desconocida',
        ]);
        $job->handle($eventProcessing, app(EventFlowService::class), $hubspotApi);

        $record->refresh();
        $this->assertNull(data_get($record->details, 'output_payload.unidad_de_medida'));
    }

    public function test_it_enriches_hubspot_property_change_payload_before_mapping(): void
    {
        $hubspotPlatform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot-main',
            'type' => 'hubspot',
            'credentials' => ['access_token' => 'token_test_123'],
            'active' => true,
        ]);
        $targetPlatform = Platform::query()->create([
            'name' => 'Odoo',
            'slug' => 'odoo-main',
            'type' => 'odoo',
            'active' => true,
        ]);

        $nextEvent = Event::query()->create([
            'platform_id' => $targetPlatform->id,
            'name' => 'Create Contact',
            'event_type_id' => 'contact.create',
            'type' => 'webhook',
            'active' => true,
        ]);
        $sourceEvent = Event::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'to_event_id' => $nextEvent->id,
            'name' => 'HubSpot Property Change',
            'event_type_id' => 'contact.propertyChange',
            'subscription_type' => 'contact.propertyChange',
            'type' => 'webhook',
            'active' => true,
        ]);

        $sourceProperty = Property::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => 'Email',
            'key' => 'email',
            'type' => 'string',
            'active' => true,
        ]);
        $targetProperty = Property::query()->create([
            'platform_id' => $targetPlatform->id,
            'name' => 'Email Destino',
            'key' => 'partner_email',
            'type' => 'string',
            'active' => true,
        ]);

        PropertyRelationship::query()->create([
            'event_id' => $sourceEvent->id,
            'property_id' => $sourceProperty->id,
            'related_property_id' => $targetProperty->id,
            'mapping_key' => 'email',
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $sourceEvent->id,
            'event_type' => 'contact.propertyChange',
            'status' => 'init',
            'payload' => ['objectId' => 401],
            'message' => 'init',
        ]);

        $eventProcessing = Mockery::mock(EventProcessingService::class);
        $eventProcessing->shouldReceive('dispatchEvent')
            ->once()
            ->withArgs(function (Event $event, Record $jobRecord, array $payload) use ($nextEvent, $record): bool {
                return $event->id === $nextEvent->id
                    && $jobRecord->id === $record->id
                    && ($payload['email'] ?? null) === 'ana@example.com'
                    && ($payload['partner_email'] ?? null) === 'ana@example.com'
                    && ($payload['hubspot_object_type'] ?? null) === 'contacts';
            });

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('getObject')
            ->once()
            ->withArgs(function (string $objectType, string $objectId, array $properties): bool {
                return $objectType === 'contacts'
                    && $objectId === '401'
                    && in_array('email', $properties, true);
            })
            ->andReturn([
                'success' => true,
                'status_code' => 200,
                'data' => [
                    'id' => '401',
                    'properties' => [
                        'email' => 'ana@example.com',
                        'firstname' => 'Ana',
                    ],
                ],
            ]);

        $payload = [
            'objectId' => 401,
            'propertyName' => 'email',
            'subscriptionType' => 'contact.propertyChange',
        ];

        $job = new ProcessNextEventJob($sourceEvent, $record, $payload);
        $job->handle($eventProcessing, app(EventFlowService::class), $hubspotApi);

        $record->refresh();
        $this->assertTrue((bool) data_get($record->details, 'hubspot_enrichment.success'));
        $this->assertSame('contacts', data_get($record->details, 'hubspot_enrichment.object_type'));
        $this->assertSame('ana@example.com', data_get($record->details, 'output_payload.partner_email'));
    }

    public function test_it_enriches_hubspot_property_change_payload_when_only_object_id_alias_is_present(): void
    {
        $hubspotPlatform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot-main',
            'type' => 'hubspot',
            'credentials' => ['access_token' => 'token_test_123'],
            'active' => true,
        ]);
        $targetPlatform = Platform::query()->create([
            'name' => 'Odoo',
            'slug' => 'odoo-main',
            'type' => 'odoo',
            'active' => true,
        ]);

        $nextEvent = Event::query()->create([
            'platform_id' => $targetPlatform->id,
            'name' => 'Create Contact',
            'event_type_id' => 'contact.create',
            'type' => 'webhook',
            'active' => true,
        ]);
        $sourceEvent = Event::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'to_event_id' => $nextEvent->id,
            'name' => 'HubSpot Property Change',
            'event_type_id' => 'contact.propertyChange',
            'subscription_type' => 'contact.propertyChange',
            'type' => 'webhook',
            'active' => true,
        ]);

        $sourceProperty = Property::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => 'Email',
            'key' => 'email',
            'type' => 'string',
            'active' => true,
        ]);
        $targetProperty = Property::query()->create([
            'platform_id' => $targetPlatform->id,
            'name' => 'Email Destino',
            'key' => 'partner_email',
            'type' => 'string',
            'active' => true,
        ]);

        PropertyRelationship::query()->create([
            'event_id' => $sourceEvent->id,
            'property_id' => $sourceProperty->id,
            'related_property_id' => $targetProperty->id,
            'mapping_key' => 'email',
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $sourceEvent->id,
            'event_type' => 'contact.propertyChange',
            'status' => 'init',
            'payload' => ['object_id' => 401],
            'message' => 'init',
        ]);

        $eventProcessing = Mockery::mock(EventProcessingService::class);
        $eventProcessing->shouldReceive('dispatchEvent')
            ->once()
            ->withArgs(function (Event $event, Record $jobRecord, array $payload) use ($nextEvent, $record): bool {
                return $event->id === $nextEvent->id
                    && $jobRecord->id === $record->id
                    && ($payload['email'] ?? null) === 'ana@example.com'
                    && ($payload['partner_email'] ?? null) === 'ana@example.com'
                    && ($payload['hubspot_object_id'] ?? null) === '401';
            });

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('getObject')
            ->once()
            ->withArgs(function (string $objectType, string $objectId, array $properties): bool {
                return $objectType === 'contacts'
                    && $objectId === '401'
                    && in_array('email', $properties, true);
            })
            ->andReturn([
                'success' => true,
                'status_code' => 200,
                'data' => [
                    'id' => '401',
                    'properties' => [
                        'email' => 'ana@example.com',
                        'firstname' => 'Ana',
                    ],
                ],
            ]);

        $payload = [
            'object_id' => 401,
            'property_name' => 'email',
            'subscription_type' => 'contact.propertyChange',
        ];

        $job = new ProcessNextEventJob($sourceEvent, $record, $payload);
        $job->handle($eventProcessing, app(EventFlowService::class), $hubspotApi);

        $record->refresh();
        $this->assertTrue((bool) data_get($record->details, 'hubspot_enrichment.success'));
        $this->assertSame('401', data_get($record->details, 'hubspot_enrichment.object_id'));
        $this->assertSame('ana@example.com', data_get($record->details, 'output_payload.partner_email'));
    }
}

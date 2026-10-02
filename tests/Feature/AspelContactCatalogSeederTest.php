<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use App\Services\EventFlowService;
use Database\Seeders\AspelContactCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AspelContactCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_configures_contact_type_catalog_for_create_and_update_flows(): void
    {
        $hubspot = Platform::query()->create([
            'name' => 'HubSpot', 'slug' => 'hubspot', 'type' => 'hubspot', 'active' => true,
        ]);
        $aspel = Platform::query()->create([
            'name' => 'ASPEL', 'slug' => 'aspel', 'type' => 'generic',
            'settings' => ['service_driver' => 'aspel'], 'active' => true,
        ]);
        $createDestination = Event::query()->create([
            'platform_id' => $aspel->id, 'name' => 'Create contact',
            'event_type_id' => 'generic.external.call', 'type' => 'node', 'active' => true,
        ]);
        $updateDestination = Event::query()->create([
            'platform_id' => $aspel->id, 'name' => 'Update contact',
            'event_type_id' => 'generic.external.call', 'type' => 'node', 'active' => true,
        ]);
        $create = Event::query()->create([
            'platform_id' => $hubspot->id, 'to_event_id' => $createDestination->id,
            'name' => 'Create flow', 'event_type_id' => 'contact.propertyChange', 'type' => 'webhook', 'active' => true,
        ]);
        $update = Event::query()->create([
            'platform_id' => $hubspot->id, 'to_event_id' => $updateDestination->id,
            'name' => 'Update flow', 'event_type_id' => 'contact.propertyChange', 'type' => 'webhook', 'active' => true,
        ]);
        $source = Property::query()->create([
            'platform_id' => $hubspot->id, 'name' => 'Tipo cliente', 'key' => 'tipo_cliente',
            'type' => 'text', 'active' => true,
        ]);
        $target = Property::query()->create([
            'platform_id' => $aspel->id, 'name' => 'Tipo empresa', 'key' => 'tipoEmpresa',
            'type' => 'string', 'active' => true,
        ]);
        foreach ([$create, $update] as $index => $event) {
            PropertyRelationship::query()->create([
                'event_id' => $event->id,
                'property_id' => $source->id,
                'related_property_id' => $target->id,
                'active' => $index === 0,
            ]);
        }

        $this->seed(AspelContactCatalogSeeder::class);

        $catalog = Platform::query()->findOrFail($aspel->id)->settings['aspel']['catalogs']['contact_types'];
        $this->assertSame([
            'PACIENTE' => 'P',
            'MEDICO' => 'M',
            'DISTRIBUIDOR' => 'D',
            'TRABAJO' => 'T',
            'CLIENTE DIVERSO' => 'CD',
        ], $catalog);
        $this->assertSame('enumeration', $source->refresh()->type);

        foreach ([$create, $update] as $event) {
            $relationship = $event->propertyRelationships()->firstOrFail();
            $this->assertTrue($relationship->active);
            $this->assertSame('aspel.catalogs.contact_types', $relationship->meta['catalog']['path']);

            foreach ($catalog as $hubspotValue => $aspelCode) {
                $transformed = app(EventFlowService::class)->transformPayloadForEvent(
                    $event->fresh('to_event.platform'),
                    ['tipo_cliente' => $hubspotValue]
                );
                $this->assertSame($aspelCode, $transformed['tipoEmpresa']);

                $reverse = app(EventFlowService::class)->resolveRelationshipCatalogValue(
                    $event->fresh('to_event.platform'),
                    $relationship,
                    $aspelCode,
                    true
                );
                $this->assertTrue($reverse['matched']);
                $this->assertSame($hubspotValue, $reverse['value']);
            }
        }
    }

    public function test_unknown_contact_type_is_not_sent_to_aspel(): void
    {
        $hubspot = Platform::query()->create([
            'name' => 'HubSpot', 'slug' => 'hubspot', 'type' => 'hubspot', 'active' => true,
        ]);
        $aspel = Platform::query()->create([
            'name' => 'ASPEL', 'slug' => 'aspel', 'type' => 'generic',
            'settings' => ['service_driver' => 'aspel'], 'active' => true,
        ]);
        $destination = Event::query()->create([
            'platform_id' => $aspel->id, 'name' => 'ASPEL destination',
            'event_type_id' => 'generic.external.call', 'type' => 'node', 'active' => true,
        ]);
        $event = Event::query()->create([
            'platform_id' => $hubspot->id, 'to_event_id' => $destination->id,
            'name' => 'Contact flow', 'event_type_id' => 'contact.propertyChange', 'type' => 'webhook', 'active' => true,
        ]);
        $source = Property::query()->create([
            'platform_id' => $hubspot->id, 'name' => 'Tipo cliente', 'key' => 'tipo_cliente',
            'type' => 'enumeration', 'active' => true,
        ]);
        $target = Property::query()->create([
            'platform_id' => $aspel->id, 'name' => 'Tipo empresa', 'key' => 'tipoEmpresa',
            'type' => 'string', 'active' => true,
        ]);
        PropertyRelationship::query()->create([
            'event_id' => $event->id, 'property_id' => $source->id,
            'related_property_id' => $target->id, 'active' => true,
        ]);
        $this->seed(AspelContactCatalogSeeder::class);

        $transformed = app(EventFlowService::class)->transformPayloadForEvent(
            $event->fresh('to_event.platform'),
            ['tipo_cliente' => 'DESCONOCIDO']
        );

        $this->assertArrayNotHasKey('tipoEmpresa', $transformed);
    }
}

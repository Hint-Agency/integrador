<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use Database\Seeders\AspelContactContractSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AspelContactContractSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_separates_classification_mappings_and_disables_hubspot_id_mapping(): void
    {
        $hubspot = Platform::query()->create([
            'name' => 'HubSpot', 'slug' => 'hubspot', 'type' => 'hubspot', 'active' => true,
        ]);
        $aspel = Platform::query()->create([
            'name' => 'ASPEL', 'slug' => 'aspel', 'type' => 'generic',
            'settings' => ['service_driver' => 'aspel'], 'active' => true,
        ]);
        $destination = Event::query()->create([
            'platform_id' => $aspel->id, 'name' => 'ASPEL contact destination',
            'event_type_id' => 'generic.external.call', 'type' => 'node', 'active' => true,
        ]);
        $event = Event::query()->create([
            'platform_id' => $hubspot->id, 'to_event_id' => $destination->id,
            'name' => 'HubSpot contact trigger', 'event_type_id' => 'contact.propertyChange',
            'type' => 'webhook', 'active' => true,
        ]);
        $createInbound = Event::query()->create([
            'platform_id' => $hubspot->id, 'name' => 'Create HubSpot contact from ASPEL',
            'event_type_id' => 'object.created', 'method_name' => 'createAspelContactInHubspot',
            'type' => 'node', 'active' => true,
        ]);
        $updateInbound = Event::query()->create([
            'platform_id' => $hubspot->id, 'to_event_id' => $createInbound->id,
            'name' => 'Update HubSpot contact from ASPEL', 'event_type_id' => 'object.updated',
            'method_name' => 'updateAspelContactInHubspot', 'type' => 'node', 'active' => true,
        ]);
        $polling = Event::query()->create([
            'platform_id' => $aspel->id, 'to_event_id' => $updateInbound->id,
            'name' => 'Poll ASPEL contacts', 'event_type_id' => 'generic.external.call',
            'method_name' => 'getUpdatedContacts', 'type' => 'schedule', 'active' => true,
        ]);
        $classification = Property::query()->create([
            'platform_id' => $hubspot->id, 'name' => 'Clasificacion', 'key' => 'clasificacion',
            'type' => 'string', 'required' => false, 'active' => true,
        ]);
        $freeClassification = Property::query()->create([
            'platform_id' => $aspel->id, 'name' => 'Clasificacion libre', 'key' => 'clasificacionLibre',
            'type' => 'string', 'required' => false, 'active' => true,
        ]);
        $hubspotId = Property::query()->create([
            'platform_id' => $hubspot->id, 'name' => 'HubSpot object ID', 'key' => 'hs_object_id',
            'type' => 'string', 'required' => false, 'active' => true,
        ]);
        $legacyRating = Property::query()->create([
            'platform_id' => $hubspot->id, 'name' => 'Legacy rating', 'key' => 'order_frequency_rating',
            'type' => 'string', 'required' => false, 'active' => true,
        ]);
        $aspelHubspotId = Property::query()->create([
            'platform_id' => $aspel->id, 'name' => 'HubSpot ID', 'key' => 'hubspotObjectId',
            'type' => 'string', 'required' => false, 'active' => true,
        ]);
        PropertyRelationship::query()->create([
            'event_id' => $event->id, 'property_id' => $classification->id,
            'related_property_id' => $freeClassification->id, 'active' => true,
        ]);
        PropertyRelationship::query()->create([
            'event_id' => $event->id, 'property_id' => $hubspotId->id,
            'related_property_id' => $aspelHubspotId->id, 'active' => true,
        ]);
        PropertyRelationship::query()->create([
            'event_id' => $event->id, 'property_id' => $legacyRating->id,
            'related_property_id' => $freeClassification->id, 'active' => true,
        ]);

        $this->seed(AspelContactContractSeeder::class);
        $this->seed(AspelContactContractSeeder::class);

        $hubspotClassification = Property::query()->where('platform_id', $hubspot->id)->where('key', 'clasificacion')->firstOrFail();
        $hubspotRating = Property::query()->where('platform_id', $hubspot->id)->where('key', 'calificacion')->firstOrFail();
        $aspelClassification = Property::query()->where('platform_id', $aspel->id)->where('key', 'clasificacion')->firstOrFail();
        $aspelFreeClassification = Property::query()->where('platform_id', $aspel->id)->where('key', 'clasificacionLibre')->firstOrFail();
        $this->assertTrue(PropertyRelationship::query()->where([
            'event_id' => $event->id,
            'property_id' => $hubspotClassification->id,
            'related_property_id' => $aspelClassification->id,
            'active' => true,
        ])->exists());
        $this->assertTrue(PropertyRelationship::query()->where([
            'event_id' => $event->id,
            'property_id' => $hubspotRating->id,
            'related_property_id' => $aspelFreeClassification->id,
            'active' => true,
        ])->exists());
        $this->assertFalse(PropertyRelationship::query()->where([
            'event_id' => $event->id,
            'related_property_id' => $aspelHubspotId->id,
            'active' => true,
        ])->exists());
        $this->assertFalse(PropertyRelationship::query()->where([
            'event_id' => $event->id,
            'property_id' => $legacyRating->id,
            'related_property_id' => $aspelFreeClassification->id,
            'active' => true,
        ])->exists());

        $this->assertTrue(PropertyRelationship::query()->where([
            'event_id' => $polling->id,
            'property_id' => $aspelClassification->id,
            'related_property_id' => $hubspotClassification->id,
            'active' => true,
        ])->exists());
        $this->assertFalse(PropertyRelationship::query()->where([
            'event_id' => $polling->id,
            'property_id' => $aspelFreeClassification->id,
            'related_property_id' => $legacyRating->id,
            'active' => true,
        ])->exists());
        $this->assertSame($polling->id, data_get($updateInbound->fresh()->meta, 'response_mapping_event_id'));
        $this->assertSame($polling->id, data_get($createInbound->fresh()->meta, 'response_mapping_event_id'));
        $this->assertSame('hubspot', data_get($updateInbound->fresh()->meta, 'target_platform'));
        $this->assertTrue((bool) data_get($polling->fresh()->meta, 'inbound_mapping_initialized'));
    }
}

<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use Illuminate\Database\Seeder;

class AspelContactContractSeeder extends Seeder
{
    public function run(): void
    {
        $hubspot = Platform::query()->where('type', 'hubspot')->first();
        $aspel = Platform::query()
            ->where('settings->service_driver', 'aspel')
            ->orWhere('slug', 'like', 'aspel%')
            ->first();

        if (! $hubspot || ! $aspel) {
            return;
        }

        $hubspotClassification = $this->property($hubspot, 'clasificacion', 'Clasificacion', 'string');
        $hubspotRating = $this->property($hubspot, 'calificacion', 'Calificacion', 'string');
        $aspelClassification = $this->property($aspel, 'clasificacion', 'CLASIFICACION', 'string');
        $aspelFreeClassification = $this->property($aspel, 'clasificacionLibre', 'CALIFICACION', 'string');
        $aspelHubspotId = $this->property($aspel, 'hubspotObjectId', 'HubSpot ID', 'string');

        $events = Event::query()
            ->where('platform_id', $hubspot->id)
            ->where('event_type_id', 'contact.propertyChange')
            ->whereHas('to_event.platform', fn ($query) => $query->whereKey($aspel->id))
            ->get();

        foreach ($events as $event) {
            PropertyRelationship::query()
                ->where('event_id', $event->id)
                ->where('property_id', $hubspotClassification->id)
                ->where('related_property_id', '!=', $aspelClassification->id)
                ->update(['active' => false]);
            PropertyRelationship::query()
                ->where('event_id', $event->id)
                ->where('related_property_id', $aspelFreeClassification->id)
                ->where('property_id', '!=', $hubspotRating->id)
                ->update(['active' => false]);

            $this->activateRelationship($event, $hubspotClassification, $aspelClassification);
            $this->activateRelationship($event, $hubspotRating, $aspelFreeClassification);

            // HubSpot IDs travel as technical flow context, never as editable mappings.
            PropertyRelationship::query()
                ->where('event_id', $event->id)
                ->where('related_property_id', $aspelHubspotId->id)
                ->update(['active' => false]);
        }

        $this->initializeInboundMappings(
            $hubspot,
            $aspel,
            $events,
            $aspelClassification,
            $aspelFreeClassification,
            $hubspotClassification,
            $hubspotRating
        );
    }

    private function property(Platform $platform, string $key, string $name, string $type): Property
    {
        return Property::query()->updateOrCreate(
            ['platform_id' => $platform->id, 'key' => $key],
            ['name' => $name, 'type' => $type, 'required' => false, 'active' => true]
        );
    }

    private function activateRelationship(Event $event, Property $source, Property $target): void
    {
        PropertyRelationship::query()->updateOrCreate(
            [
                'event_id' => $event->id,
                'property_id' => $source->id,
                'related_property_id' => $target->id,
            ],
            ['active' => true]
        );
    }

    private function initializeInboundMappings(
        Platform $hubspot,
        Platform $aspel,
        $outboundEvents,
        Property $aspelClassification,
        Property $aspelFreeClassification,
        Property $hubspotClassification,
        Property $hubspotRating
    ): void {
        $pollingEvent = Event::query()
            ->where('platform_id', $aspel->id)
            ->where('method_name', 'getUpdatedContacts')
            ->first();

        if (! $pollingEvent) {
            return;
        }

        $pollingMeta = $pollingEvent->meta ?? [];
        if (! ($pollingMeta['inbound_mapping_initialized'] ?? false)) {
            $outboundEvent = $outboundEvents->first(
                fn (Event $event): bool => ($event->meta['sync_mode'] ?? null) === 'controlled'
            ) ?? $outboundEvents->first();

            if ($outboundEvent) {
                $outboundEvent->load('propertyRelationships.property', 'propertyRelationships.relatedProperty');
                foreach ($outboundEvent->propertyRelationships->where('active', true) as $relationship) {
                    $source = $relationship->property;
                    $target = $relationship->relatedProperty;
                    if (! $source || ! $target
                        || (int) $source->platform_id !== (int) $hubspot->id
                        || (int) $target->platform_id !== (int) $aspel->id
                        || $target->key === 'hubspotObjectId') {
                        continue;
                    }

                    PropertyRelationship::query()->updateOrCreate(
                        [
                            'event_id' => $pollingEvent->id,
                            'property_id' => $target->id,
                            'related_property_id' => $source->id,
                        ],
                        [
                            'mapping_key' => null,
                            'active' => true,
                            'meta' => $this->inboundMeta($relationship->meta ?? []),
                        ]
                    );
                }
            }

            $pollingMeta['inbound_mapping_initialized'] = true;
            $pollingEvent->update(['meta' => $pollingMeta]);
        }

        PropertyRelationship::query()
            ->where('event_id', $pollingEvent->id)
            ->where('property_id', $aspelFreeClassification->id)
            ->where('related_property_id', '!=', $hubspotRating->id)
            ->update(['active' => false]);
        $this->activateRelationship($pollingEvent, $aspelClassification, $hubspotClassification);
        $this->activateRelationship($pollingEvent, $aspelFreeClassification, $hubspotRating);

        Event::query()
            ->where('platform_id', $hubspot->id)
            ->whereIn('method_name', ['updateAspelContactInHubspot', 'createAspelContactInHubspot'])
            ->get()
            ->each(function (Event $event) use ($pollingEvent): void {
                $meta = $event->meta ?? [];
                $meta['source_platform'] = 'aspel';
                $meta['target_platform'] = 'hubspot';
                $meta['response_mapping_event_id'] = $pollingEvent->id;
                $event->update(['meta' => $meta]);
            });
    }

    private function inboundMeta(array $meta): array
    {
        if (isset($meta['catalog']) && is_array($meta['catalog'])) {
            $meta['catalog']['platform'] = 'source';
            $meta['catalog']['match'] = 'value';
            $meta['catalog']['output'] = 'key';
        }

        return $meta;
    }
}

<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Property;
use App\Models\PropertyRelationship;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AspelWarehouseMappingsSeeder extends Seeder
{
    public function run(): void
    {
        $events = Event::query()->with('from_events')
            ->where('method_name', 'syncLineItemExecutionResponse')->get();

        foreach ($events as $event) {
            $sourceEvent = $event->from_events->firstWhere('method_name', 'syncLineItemWarehouseInventory');
            if (! $sourceEvent) {
                continue;
            }

            DB::transaction(function () use ($event, $sourceEvent) {
                $sourceEvent->update(['meta' => array_merge([
                    'require_customer_context' => true,
                    'primary_contact_association_name' => 'main_contact',
                    'customer_clave_property' => 'clave',
                ], $sourceEvent->meta ?? [])]);
                foreach (['precio' => 'number', 'listaPrecio' => 'number', 'origenLista' => 'string', 'incluyeImpuestos' => 'boolean'] as $key => $type) {
                    Property::query()->firstOrCreate([
                        'platform_id' => $sourceEvent->platform_id, 'key' => $key,
                    ], ['name' => $key, 'type' => $type, 'active' => true]);
                }
                foreach ([
                    ['exist', 'existencias', 'Existencias'],
                    ['stockMax', 'stock_maximo', 'Stock maximo'],
                    ['stockMin', 'stock_minimo', 'Stock minimo'],
                ] as [$sourceKey, $targetKey, $label]) {
                    $source = Property::query()->firstOrCreate([
                        'platform_id' => $sourceEvent->platform_id, 'key' => $sourceKey,
                    ], ['name' => $label, 'type' => 'number', 'active' => true]);
                    $target = Property::query()->firstOrCreate([
                        'platform_id' => $event->platform_id, 'key' => $targetKey,
                    ], ['name' => $label, 'type' => 'number', 'active' => true]);

                    if ($event->propertyRelationships()->where('related_property_id', $target->id)->exists()) {
                        continue;
                    }

                    PropertyRelationship::query()->firstOrCreate([
                        'event_id' => $event->id,
                        'property_id' => $source->id,
                        'related_property_id' => $target->id,
                    ], [
                        'mapping_key' => 'destination_response.data.'.$targetKey,
                        'active' => true,
                        'meta' => [],
                    ]);
                }
            });
        }
    }
}

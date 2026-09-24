<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use App\Services\EventTriggerService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AspelQuoteFlowSeeder extends Seeder
{
    public function run(): void
    {
        $hubspot = Platform::where('type', 'hubspot')->first();
        $create = Event::with('platform')->where('method_name', 'createQuote')
            ->whereHas('platform', fn ($query) => $query->where('settings->service_driver', 'aspel'))->first();
        if (! $hubspot || ! $create) {
            return;
        }
        $warehouse = Event::where('platform_id', $create->platform_id)->where('method_name', 'syncLineItemWarehouseInventory')->first();
        if (! $warehouse) {
            return;
        }
        DB::transaction(function () use ($hubspot, $create, $warehouse) {
            $writeback = Event::firstOrCreate(['platform_id' => $hubspot->id, 'method_name' => 'syncQuoteExecutionResponse'], [
                'name' => 'Guardar cotizacion SAE en HubSpot', 'event_type_id' => 'object.updated', 'type' => 'node',
                'meta' => ['object_type' => 'quotes', 'target_platform' => 'aspel'], 'active' => true,
            ]);
            $prepare = Event::firstOrCreate(['platform_id' => $hubspot->id, 'method_name' => 'prepareAspelQuote'], [
                'name' => 'Preparar y validar cotizacion SAE', 'event_type_id' => 'object.updated', 'type' => 'node',
                'to_event_id' => $create->id, 'meta' => [
                    'object_type' => 'quotes', 'warehouse_event_id' => $warehouse->id,
                    'primary_contact_association_type_id' => 1, 'primary_contact_association_category' => 'USER_DEFINED',
                    'customer_clave_property' => 'clave',
                ], 'active' => true,
            ]);
            if (! isset($prepare->meta['mapping_context'])) {
                $prepare->update(['meta' => array_merge($prepare->meta ?? [], ['mapping_context' => [
                    'source_platform_ids' => [$hubspot->id], 'target_platform_id' => $create->platform_id,
                ]])]);
            }
            $trigger = Event::firstOrCreate(['platform_id' => $hubspot->id, 'name' => 'Enviar cotizacion SAE por etapa de negocio'], [
                'method_name' => 'dealPropertyChange', 'event_type_id' => 'deal.propertyChange', 'type' => 'webhook',
                'to_event_id' => $prepare->id, 'meta' => ['object_type' => 'deals', 'target_platform' => 'aspel', 'required_source_properties' => ['dealstage']],
                'active' => true,
            ]);
            $stage = $this->property($hubspot->id, 'dealstage', 'string', 'deals');
            $trigger->properties()->syncWithoutDetaching([$stage->id]);
            if (! $trigger->eventTriggers()->exists() && ! \App\Models\EventTriggerGroup::where('event_id', $trigger->id)->exists()) {
                app(EventTriggerService::class)->syncEventTriggers($trigger, [
                    ['name' => 'Cambio de etapa', 'operator' => 'and', 'active' => true, 'conditions' => [
                        ['field' => 'propertyName', 'operator' => 'equals', 'value' => 'dealstage'],
                    ]],
                    ['name' => 'Etapas autorizadas', 'operator' => 'or', 'active' => true,
                        'conditions' => array_map(static fn ($value): array => [
                            'field' => 'propertyValue', 'operator' => 'equals', 'value' => $value,
                        ], ['1316578321', '1160755464', '1316741897', '1316738978']),
                    ],
                ]);
            }
            if (! $create->to_event_id) {
                $create->update(['to_event_id' => $writeback->id]);
            }
            $mappings = [
                ['contact', 'clave', 'claveCliente', 'string'],
                ['contact', 'lista_de_precios', 'expectedPriceList', 'string'],
                ['quote', 'hs_sender_email', 'correoVendedor', 'string'],
                ['contact', 'firstname', 'direccionEnvio.nombre', 'string', 'contact_name'],
                ['contact', 'calle_envio', 'direccionEnvio.calle', 'string'],
                ['contact', 'num_int_envio', 'direccionEnvio.numeroInterior', 'string'],
                ['contact', 'num_ext_envio', 'direccionEnvio.numeroExterior', 'string'],
                ['contact', 'poblacion_envio', 'direccionEnvio.poblacion', 'string'],
                ['contact', 'referencia_envio', 'direccionEnvio.referencia', 'string'],
                ['contact', 'colonia_envio', 'direccionEnvio.colonia', 'string'],
                ['contact', 'estado_envio', 'direccionEnvio.estado', 'string'],
                ['contact', 'pais_envio', 'direccionEnvio.pais', 'string'],
                ['contact', 'codigo_postal_envio', 'direccionEnvio.codigoPostal', 'string'],
                ['contact', 'forma_de_pago', 'formaPagoSat', 'string'],
                ['contact', 'cfdi_nombre', 'usoCfdi', 'string'],
                ['contact', 'catalogo_regimen_fiscal', 'regimenFiscal', 'string'],
                ['line_item', 'clave', 'cveArt', 'string'],
                ['line_item', 'quantity', 'cantidad', 'decimal'],
                ['line_item', 'almacen_id', 'cveAlmacen', 'string'],
                ['line_item', 'prices_list', 'listaPrecio', 'string'],
                ['line_item', 'price', 'precioUnitario', 'decimal'],
            ];
            foreach ($mappings as $mapping) {
                [$object, $sourceKey, $targetKey, $type] = $mapping;
                $source = $this->property($hubspot->id, $sourceKey, $type, ['contact' => 'contacts', 'quote' => 'quotes', 'line_item' => 'line_items'][$object]);
                $target = $this->property($create->platform_id, $targetKey, $type, 'quotes');
                PropertyRelationship::firstOrCreate(['event_id' => $prepare->id, 'related_property_id' => $target->id], [
                    'property_id' => $source->id, 'mapping_key' => $mapping[4] ?? $object.'.properties.'.$sourceKey,
                    'active' => true, 'meta' => ['scope' => $object === 'line_item' ? 'line_item' : 'header'],
                ]);
            }
            foreach (['cveDoc' => 'aspel_cve_doc', 'folio' => 'aspel_folio', 'serie' => 'aspel_serie'] as $sourceKey => $targetKey) {
                $source = $this->property($create->platform_id, $sourceKey, 'string', 'quotes');
                $target = $this->property($hubspot->id, $targetKey, 'string', 'quotes');
                PropertyRelationship::firstOrCreate(['event_id' => $writeback->id, 'related_property_id' => $target->id], [
                    'property_id' => $source->id, 'mapping_key' => 'destination_response.data.'.$sourceKey, 'meta' => [], 'active' => true,
                ]);
            }
            $lineWriteback = $warehouse->to_event;
            if ($lineWriteback?->method_name === 'syncLineItemExecutionResponse') {
                foreach (['precio' => 'price', 'listaPrecio' => 'prices_list'] as $sourceKey => $targetKey) {
                    $source = $this->property($create->platform_id, $sourceKey, 'number', 'line_items');
                    $target = $this->property($hubspot->id, $targetKey, 'string', 'line_items');
                    PropertyRelationship::firstOrCreate(['event_id' => $lineWriteback->id, 'related_property_id' => $target->id], [
                        'property_id' => $source->id, 'mapping_key' => 'destination_response.data.'.$sourceKey,
                        'meta' => [], 'active' => true,
                    ]);
                }
            }
            foreach (['sync_status_aspel' => 'enumeration', 'last_sync_aspel' => 'datetime', 'last_error_aspel' => 'string'] as $key => $type) {
                $this->property($hubspot->id, $key, $type, 'quotes');
            }
        });
    }

    private function property(int $platformId, string $key, string $type, string $object): Property
    {
        $query = Property::where('platform_id', $platformId)->where('key', $key);
        $existing = (clone $query)->where('meta->hubspot_object_type', $object)->first()
            ?? (clone $query)->whereNull('meta->hubspot_object_type')->first();
        return $existing ?? Property::create([
            'platform_id' => $platformId, 'key' => $key, 'name' => $key, 'type' => $type,
            'active' => true, 'meta' => ['hubspot_object_type' => $object],
        ]);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class AspelContactCatalogSeeder extends Seeder
{
    private const CATALOG_PATH = 'aspel.catalogs.contact_types';

    private const CONTACT_TYPES = [
        'PACIENTE' => 'P',
        'MEDICO' => 'M',
        'DISTRIBUIDOR' => 'D',
        'TRABAJO' => 'T',
        'CLIENTE DIVERSO' => 'CD',
    ];

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

        $settings = $aspel->settings ?? [];
        Arr::set($settings, self::CATALOG_PATH, self::CONTACT_TYPES);
        $aspel->update(['settings' => $settings]);

        $source = Property::query()
            ->where('platform_id', $hubspot->id)
            ->where('key', 'tipo_cliente')
            ->first();
        $target = Property::query()
            ->where('platform_id', $aspel->id)
            ->where('key', 'tipoEmpresa')
            ->first();

        if (! $source || ! $target) {
            return;
        }

        if ($source->type !== 'enumeration') {
            $source->update(['type' => 'enumeration']);
        }

        PropertyRelationship::query()
            ->where('property_id', $source->id)
            ->where('related_property_id', $target->id)
            ->whereHas('event', fn ($query) => $query->where('platform_id', $hubspot->id))
            ->get()
            ->each(function (PropertyRelationship $relationship): void {
                $meta = $relationship->meta ?? [];
                $meta['catalog'] = [
                    'path' => self::CATALOG_PATH,
                    'platform' => 'target',
                    'match' => 'key',
                    'output' => 'value',
                ];

                $relationship->update([
                    'active' => true,
                    'meta' => $meta,
                ]);
            });
    }
}

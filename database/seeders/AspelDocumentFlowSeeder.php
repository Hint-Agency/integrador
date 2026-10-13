<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventHttpConfig;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AspelDocumentFlowSeeder extends Seeder
{
    public function run(): void
    {
        $hubspot = Platform::query()->where('type', 'hubspot')->first();
        $aspel = Platform::query()
            ->where('settings->service_driver', 'aspel')
            ->first();

        if (! $hubspot || ! $aspel) {
            return;
        }

        $templateHttpConfig = EventHttpConfig::query()
            ->whereHas('event', fn ($query) => $query->where('platform_id', $aspel->id))
            ->whereNotNull('base_url')
            ->orderByDesc('active')
            ->first();

        DB::transaction(function () use ($hubspot, $aspel, $templateHttpConfig): void {
            $this->seedDocumentFlow(
                $hubspot,
                $aspel,
                $templateHttpConfig,
                'remissions',
                'getUpdatedRemissions',
                'Polling Remisiones ASPEL a HubSpot',
                'Sincronizar remision ASPEL con negocio HubSpot',
                'api/remissions/changes',
                'clave_remision',
                'amount_remision'
            );

            $this->seedDocumentFlow(
                $hubspot,
                $aspel,
                $templateHttpConfig,
                'invoices',
                'getUpdatedInvoices',
                'Polling Facturas ASPEL a HubSpot',
                'Sincronizar factura ASPEL con negocio HubSpot',
                'api/invoices/changes',
                'clave_factura',
                'amount'
            );
        });
    }

    private function seedDocumentFlow(
        Platform $hubspot,
        Platform $aspel,
        ?EventHttpConfig $templateHttpConfig,
        string $scope,
        string $pollingMethod,
        string $pollingName,
        string $syncName,
        string $path,
        string $documentKeyProperty,
        string $amountProperty
    ): void {
        $syncEvent = Event::query()->firstOrCreate(
            [
                'platform_id' => $hubspot->id,
                'method_name' => 'syncAspelDocumentToHubspotDeal',
                'name' => $syncName,
            ],
            [
                'event_type_id' => 'object.updated',
                'type' => 'webhook',
                'active' => true,
            ]
        );

        $pollingEvent = Event::query()->firstOrCreate(
            [
                'platform_id' => $aspel->id,
                'method_name' => $pollingMethod,
            ],
            [
                'name' => $pollingName,
                'event_type_id' => 'generic.external.call',
                'type' => 'schedule',
                'schedule_expression' => '*/5 * * * *',
                'active' => true,
            ]
        );

        $pollingEvent->update([
            'to_event_id' => $syncEvent->id,
            'meta' => array_merge([
                'take' => 200,
                'object_type' => 'deals',
                'document_scope' => $scope,
                'source_platform' => 'aspel',
                'target_platform' => 'hubspot',
                'initial_lookback_hours' => 24,
                'overlap_minutes' => 15,
                'detail_not_found_retry_seconds' => [1, 3],
            ], $pollingEvent->meta ?? []),
        ]);

        $syncEvent->update([
            'meta' => array_merge([
                'object_type' => 'deals',
                'document_scope' => $scope,
                'source_platform' => 'aspel',
                'target_platform' => 'hubspot',
            ], $syncEvent->meta ?? [], [
                'mapping_event_id' => $pollingEvent->id,
            ]),
        ]);

        $existingHttpConfig = $pollingEvent->httpConfig()->first();
        $pollingEvent->httpConfig()->updateOrCreate(
            ['event_id' => $pollingEvent->id],
            [
                'method' => 'GET',
                'base_url' => $existingHttpConfig?->base_url ?? $templateHttpConfig?->base_url,
                'path' => $path,
                'headers_json' => $existingHttpConfig?->headers_json ?? $templateHttpConfig?->headers_json ?? [],
                'query_json' => [],
                'auth_mode' => $existingHttpConfig?->auth_mode ?? $templateHttpConfig?->auth_mode,
                'auth_config_json' => $existingHttpConfig?->auth_config_json ?? $templateHttpConfig?->auth_config_json ?? [],
                'timeout_seconds' => $existingHttpConfig?->timeout_seconds ?? $templateHttpConfig?->timeout_seconds ?? 30,
                'retry_policy_json' => $existingHttpConfig?->retry_policy_json ?? $templateHttpConfig?->retry_policy_json ?? [],
                'idempotency_config_json' => [],
                'allowlist_domains_json' => $existingHttpConfig?->allowlist_domains_json ?? $templateHttpConfig?->allowlist_domains_json ?? [],
                'active' => true,
            ]
        );

        $this->mapping($pollingEvent, $aspel, $hubspot, 'cveDoc', $documentKeyProperty, 'string', 'string', $scope);
        $this->mapping($pollingEvent, $aspel, $hubspot, 'importe', $amountProperty, 'number', 'number', $scope);
    }

    private function mapping(
        Event $event,
        Platform $sourcePlatform,
        Platform $targetPlatform,
        string $sourceKey,
        string $targetKey,
        string $sourceType,
        string $targetType,
        string $scope
    ): void {
        $source = Property::query()->firstOrCreate([
            'platform_id' => $sourcePlatform->id,
            'key' => $sourceKey,
            'meta->aspel_document_scope' => $scope,
        ], [
            'name' => $sourceKey,
            'type' => $sourceType,
            'active' => true,
            'meta' => ['aspel_document_scope' => $scope],
        ]);

        $target = Property::query()
            ->where('platform_id', $targetPlatform->id)
            ->where('key', $targetKey)
            ->where('meta->hubspot_object_type', 'deals')
            ->first();

        $target ??= Property::query()->create([
            'platform_id' => $targetPlatform->id,
            'key' => $targetKey,
            'name' => $targetKey,
            'type' => $targetType,
            'active' => true,
            'meta' => ['hubspot_object_type' => 'deals'],
        ]);

        PropertyRelationship::query()->firstOrCreate([
            'event_id' => $event->id,
            'property_id' => $source->id,
            'related_property_id' => $target->id,
        ], [
            'mapping_key' => $sourceKey,
            'active' => true,
            'meta' => $sourceType === 'number' ? ['transform' => 'decimal'] : [],
        ]);
    }
}

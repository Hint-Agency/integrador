<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Record;
use App\Services\EventFlowService;
use App\Services\EventProcessingService;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class ProcessNextEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 300;

    public function __construct(
        public Event $event,
        public Record $record,
        public array $data
    ) {
        $this->onQueue('events');
    }

    public function handle(
        EventProcessingService $eventProcessingService,
        EventFlowService $eventFlowService,
        HubspotApiServiceRefactored $hubspotApiService
    ): void
    {
        $nextEvent = $this->event->to_event;
        if (! $nextEvent) {
            $this->record->update([
                'status' => 'warning',
                'message' => 'No next event to process',
            ]);
            return;
        }

        $preparedPayload = $this->preparePayloadForNextEvent($nextEvent, $eventFlowService, $hubspotApiService);

        $this->mergeRecordDetails([
            'output_payload' => $preparedPayload,
            'output_generated_at' => now()->toISOString(),
            'next_event' => [
                'id' => $nextEvent->id,
                'name' => $nextEvent->name,
                'event_type_id' => $nextEvent->event_type_id,
            ],
        ]);

        $eventProcessingService->dispatchEvent($nextEvent, $this->record, $preparedPayload);
    }

    private function preparePayloadForNextEvent(
        Event $nextEvent,
        EventFlowService $eventFlowService,
        HubspotApiServiceRefactored $hubspotApiService
    ): array {
        $payload = $this->maybeEnrichHubspotObjectPayload($this->event, $this->data, $hubspotApiService);

        return $eventFlowService->transformPayloadForEvent($this->event, $payload);
    }

    private function maybeEnrichHubspotObjectPayload(
        Event $mappingEvent,
        array $payload,
        HubspotApiServiceRefactored $hubspotApiService
    ): array {
        if (($this->event->platform?->type ?? null) !== 'hubspot') {
            return $payload;
        }

        $subscriptionType = strtolower((string) ($this->event->getSubscriptionType() ?? ''));
        if (! str_contains($subscriptionType, '.propertychange')) {
            return $payload;
        }

        $objectId = trim((string) ($payload['objectId'] ?? $payload['object_id'] ?? $payload['id'] ?? ''));
        if ($objectId === '') {
            $this->mergeRecordDetails([
                'hubspot_enrichment' => [
                    'attempted' => false,
                    'reason' => 'object_id_missing',
                ],
            ]);

            return $payload;
        }

        $objectType = $this->resolveHubspotObjectType($subscriptionType, $payload);
        if (! $objectType) {
            $this->mergeRecordDetails([
                'hubspot_enrichment' => [
                    'attempted' => false,
                    'reason' => 'object_type_unresolved',
                    'subscription_type' => $subscriptionType,
                ],
            ]);

            return $payload;
        }

        $properties = $this->resolveRequestedHubspotProperties($mappingEvent, $payload);
        $this->applyHubspotRuntimeConfig();

        $response = $hubspotApiService->getObject($objectType, $objectId, $properties);
        if (! ($response['success'] ?? false)) {
            $this->mergeRecordDetails([
                'hubspot_enrichment' => [
                    'attempted' => true,
                    'success' => false,
                    'object_type' => $objectType,
                    'object_id' => $objectId,
                    'requested_properties' => $properties,
                    'error' => $response['error'] ?? null,
                    'status_code' => $response['status_code'] ?? null,
                ],
            ]);

            return $payload;
        }

        $objectData = Arr::get($response, 'data', []);
        $objectProperties = Arr::get($objectData, 'properties', []);
        if (! is_array($objectProperties)) {
            $objectProperties = [];
        }

        $enrichedPayload = array_merge($payload, $objectProperties, [
            'hubspot_object_id' => $objectId,
            'hubspot_object_type' => $objectType,
            'hubspot_object' => $objectData,
        ]);

        $this->mergeRecordDetails([
            'hubspot_enrichment' => [
                'attempted' => true,
                'success' => true,
                'object_type' => $objectType,
                'object_id' => $objectId,
                'requested_properties' => $properties,
                'fetched_properties' => array_keys($objectProperties),
            ],
        ]);

        return $enrichedPayload;
    }

    /**
     * @return array<int, string>
     */
    private function resolveRequestedHubspotProperties(Event $mappingEvent, array $payload): array
    {
        $requested = [];
        $relationships = $mappingEvent->propertyRelationships()->with('property')->where('active', true)->get();

        foreach ($relationships as $relationship) {
            $sourceKey = $relationship->mapping_key
                ?: ($relationship->property?->key ?: $relationship->property?->name);

            if (! is_string($sourceKey) || trim($sourceKey) === '') {
                continue;
            }

            $requested[] = $this->normalizeHubspotPropertyKey($sourceKey);
        }

        $requiredProperties = $mappingEvent->meta['required_source_properties'] ?? [];
        if (is_array($requiredProperties)) {
            foreach ($requiredProperties as $requiredProperty) {
                if (! is_scalar($requiredProperty)) {
                    continue;
                }

                $requested[] = $this->normalizeHubspotPropertyKey((string) $requiredProperty);
            }
        }

        $changedProperty = $payload['propertyName'] ?? ($payload['property_name'] ?? null);
        if (is_scalar($changedProperty)) {
            $requested[] = $this->normalizeHubspotPropertyKey((string) $changedProperty);
        }

        return array_values(array_unique(array_filter($requested)));
    }

    private function normalizeHubspotPropertyKey(string $key): string
    {
        $value = trim($key);
        if (str_starts_with($value, 'properties.')) {
            $value = substr($value, strlen('properties.'));
        }

        if (str_contains($value, '.')) {
            $segments = explode('.', $value);
            $value = (string) end($segments);
        }

        return trim($value);
    }

    private function resolveHubspotObjectType(string $subscriptionType, array $payload): ?string
    {
        $prefix = trim((string) explode('.', $subscriptionType)[0]);

        if ($prefix === 'object') {
            $metaType = trim((string) ($this->event->meta['object_type'] ?? ($payload['objectType'] ?? '')));
            if ($metaType === '') {
                return null;
            }

            return $this->normalizeHubspotObjectType($metaType);
        }

        return $this->normalizeHubspotObjectType($prefix);
    }

    private function normalizeHubspotObjectType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'contact', 'contacts' => 'contacts',
            'company', 'companies' => 'companies',
            'deal', 'deals' => 'deals',
            'ticket', 'tickets' => 'tickets',
            'quote', 'quotes' => 'quotes',
            'product', 'products' => 'products',
            'line_item', 'line_items' => 'line_items',
            default => strtolower(trim($type)),
        };
    }

    private function applyHubspotRuntimeConfig(): void
    {
        $credentials = $this->event->platform?->credentials ?? [];
        $settings = $this->event->platform?->settings ?? [];
        $overrides = [];

        $token = $credentials['access_token'] ?? $credentials['api_token'] ?? null;
        if (is_string($token) && trim($token) !== '') {
            $overrides['hubspot.access_token'] = $token;
        }

        $baseUrl = $settings['base_url'] ?? null;
        if (is_string($baseUrl) && trim($baseUrl) !== '') {
            $overrides['hubspot.base_url'] = $baseUrl;
        }

        if (! empty($overrides)) {
            config($overrides);
        }
    }

    private function mergeRecordDetails(array $details): void
    {
        $currentDetails = is_array($this->record->details) ? $this->record->details : [];
        $this->record->update([
            'details' => array_replace_recursive($currentDetails, $details),
        ]);
    }
}

<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\PropertyRelationship;
use App\Models\Record;
use App\Services\EventLoggingService;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use App\Services\Hubspot\HubspotFilePropertyService;
use App\Services\Hubspot\HubspotService;
use App\Services\Odoo\OdooService;
use App\Services\SignedQuotesPipelineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class CreateOrUpdateEntityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 300;

    public function __construct(
        public array $payload,
        public Event $event,
        public Record $record
    ) {
        $this->onQueue('processing');
    }

    public function handle(
        EventLoggingService $eventLoggingService,
        SignedQuotesPipelineService $pipelineService,
        HubspotApiServiceRefactored $hubspotApi
    ): void {
        $this->record->update([
            'status' => 'processing',
            'message' => 'Creating or updating entities',
        ]);

        $targetPlatform = (string) Arr::get($this->payload, 'target_platform', 'odoo');
        $this->event->loadMissing([
            'platform',
            'to_event.platform',
            'propertyRelationships.property',
            'propertyRelationships.relatedProperty',
        ]);
        $odooService = $this->resolveOdooService($targetPlatform);
        if ($this->event->platform?->type === 'hubspot') {
            app()->make(HubspotService::class, [
                'platform' => $this->event->platform,
                'event' => $this->event,
                'record' => $this->record,
            ]);
        }
        $processedQuotes = [];
        $continuableQuotes = [];
        $blockedQuotes = [];
        $sourceWritebacks = [];

        foreach (Arr::get($this->payload, 'quotes', []) as $quote) {
            $processed = $this->processQuoteEntities($quote, $targetPlatform, $pipelineService, $odooService);
            $processedQuotes[] = $processed;
            $writeback = $this->writeBackPartnerIdsToHubspot($processed, $hubspotApi);
            $sourceWritebacks[] = $writeback;

            $blockReason = $this->partnerBlockReason($processed, $writeback);
            if ($blockReason === null) {
                $continuableQuotes[] = $processed;
            } else {
                $blockedQuotes[] = [
                    'quote_id' => $processed['quote_id'] ?? null,
                    'hubspot_quote_id' => $processed['hubspot_quote_id'] ?? null,
                    'reason' => $blockReason,
                    'entity_results' => Arr::get($processed, 'entity_results', []),
                    'source_writeback' => $writeback,
                ];
            }
        }

        if ($continuableQuotes !== []) {
            $updateRecord = $eventLoggingService->createEventRecord(
                $this->event->event_type_id ?? 'signed_quotes',
                'init',
                [
                    'quotes' => $continuableQuotes,
                    'summary' => Arr::get($this->payload, 'summary', []),
                    'target_platform' => $targetPlatform,
                ],
                'Resolving quote associations',
                $this->record->id,
                $this->event->id
            );

            ResolveAssociationsJob::dispatch([
                'quotes' => $continuableQuotes,
                'summary' => Arr::get($this->payload, 'summary', []),
                'target_platform' => $targetPlatform,
            ], $this->event, $updateRecord)
                ->onQueue('processing');
        }

        $status = $blockedQuotes === [] ? 'success' : ($continuableQuotes === [] ? 'error' : 'warning');
        $message = match ($status) {
            'success' => 'Entity create/update completed',
            'warning' => 'Some quotes were blocked before subscription creation',
            default => 'Quote blocked before subscription creation',
        };

        $this->record->update([
            'status' => $status,
            'message' => $message,
            'details' => [
                'target_platform' => $targetPlatform,
                'quotes_total' => count($processedQuotes),
                'continuable_count' => count($continuableQuotes),
                'blocked_count' => count($blockedQuotes),
                'blocked_quotes' => $blockedQuotes,
                'source_writebacks' => $sourceWritebacks,
                'next_job' => $continuableQuotes === [] ? null : ResolveAssociationsJob::class,
            ],
        ]);
    }

    private function processQuoteEntities(array $quote, string $targetPlatform, SignedQuotesPipelineService $pipelineService, ?OdooService $odooService): array
    {
        $company = $this->processPartnerEntityAction(
            Arr::get($quote, 'entity_actions.company', []),
            $quote['quote_id'] ?? 'quote',
            $targetPlatform,
            $pipelineService,
            $odooService,
            'company'
        );

        $contacts = [];
        foreach ($this->contactEntityActions($quote) as $index => $contactAction) {
            $contacts[] = $this->processPartnerEntityAction(
                $contactAction,
                ($quote['quote_id'] ?? 'quote').'_contact_'.($index + 1),
                $targetPlatform,
                $pipelineService,
                $odooService,
                'contact',
                is_numeric($company['target_id'] ?? null) ? (int) $company['target_id'] : null
            );
        }

        $contact = $contacts[0] ?? $this->processEntityAction(
            Arr::get($quote, 'entity_actions.contact', []),
            ($quote['quote_id'] ?? 'quote').'_contact',
            $targetPlatform,
            $pipelineService
        );

        $products = [];
        foreach (Arr::get($quote, 'entity_actions.products', []) as $index => $productAction) {
            $products[] = $this->processEntityAction(
                $productAction,
                ($quote['quote_id'] ?? 'quote').'_product_'.($index + 1),
                $targetPlatform,
                $pipelineService
            );
        }

        return [
            'quote_id' => $quote['quote_id'] ?? null,
            'hubspot_quote_id' => $quote['hubspot_quote_id'] ?? null,
            'entity_results' => [
                'company' => $company,
                'contact' => $contact,
                'contacts' => $contacts,
                'products' => $products,
            ],
            'raw' => $quote['raw'] ?? [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contactEntityActions(array $quote): array
    {
        $actions = [];
        $seen = [];

        $primary = Arr::get($quote, 'entity_actions.contact', []);
        if (is_array($primary) && $primary !== []) {
            $actions[] = $primary;
            $primaryId = $this->contactActionHubspotId($primary);
            if ($primaryId !== null) {
                $seen[$primaryId] = true;
            }
        }

        $contacts = Arr::get($quote, 'raw.associations.contacts', []);
        if (! is_array($contacts)) {
            return $actions;
        }

        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $hubspotId = $this->firstScalar([
                Arr::get($contact, 'id'),
                Arr::get($contact, 'properties.hs_object_id'),
                Arr::get($contact, 'association.toObjectId'),
            ]);

            if ($hubspotId !== null && isset($seen[$hubspotId])) {
                continue;
            }

            if ($hubspotId !== null) {
                $seen[$hubspotId] = true;
            }

            $fields = Arr::get($contact, 'properties', []);
            if (! is_array($fields)) {
                $fields = [];
            }

            if ($hubspotId !== null) {
                $fields['hs_object_id'] ??= $hubspotId;
            }

            $actions[] = [
                'action' => Arr::get($fields, 'odoo_id') ? 'update' : 'create',
                'entity_type' => 'contact',
                'entity' => [
                    'id' => $hubspotId,
                    'hubspot_id' => $hubspotId,
                    'odoo_id' => Arr::get($fields, 'odoo_id'),
                ],
                'target_id' => Arr::get($fields, 'odoo_id'),
                'changed_fields' => array_keys($fields),
                'fields' => $fields,
            ];
        }

        return $actions;
    }

    private function contactActionHubspotId(array $action): ?string
    {
        return $this->firstScalar([
            Arr::get($action, 'entity.hubspot_id'),
            Arr::get($action, 'entity.id'),
            Arr::get($action, 'fields.hubspot_object_id'),
            Arr::get($action, 'fields.hs_object_id'),
        ]);
    }

    private function processPartnerEntityAction(
        array $action,
        string $reference,
        string $targetPlatform,
        SignedQuotesPipelineService $pipelineService,
        ?OdooService $odooService,
        string $entityType,
        ?int $parentId = null
    ): array {
        if ($targetPlatform !== 'odoo' || ! $odooService) {
            return $this->processEntityAction($action, $reference, $targetPlatform, $pipelineService);
        }

        $payload = $this->buildMappedPartnerPayload($action, $entityType);
        if ($parentId) {
            $payload['parent_id'] = $parentId;
        }

        $result = $entityType === 'company'
            ? $odooService->resPartnerCreateCompany($payload)
            : $odooService->resPartnerCreateOrUpdateContact($payload);

        if (! ($result['success'] ?? false)) {
            return [
                ...$this->processEntityAction($action, $reference, $targetPlatform, $pipelineService),
                'operation' => 'error',
                'error' => $result['message'] ?? 'Odoo partner upsert failed.',
                'fields' => $payload,
                'service_result' => $result,
            ];
        }

        return [
            'entity_type' => $entityType,
            'operation' => Arr::get($result, 'data.operation', 'updated'),
            'target_id' => Arr::get($result, 'data.id'),
            'changed_fields' => $action['changed_fields'] ?? [],
            'fields' => $payload,
            'matched_by' => Arr::get($result, 'data.matched_by'),
            'output_payload' => Arr::get($result, 'data.output_payload'),
        ];
    }

    private function processEntityAction(array $action, string $reference, string $targetPlatform, SignedQuotesPipelineService $pipelineService): array
    {
        $operation = $action['action'] ?? 'no_change';
        $entityType = $action['entity_type'] ?? 'entity';
        $targetId = $action['target_id'] ?? null;

        if ($operation === 'create') {
            $targetId = $pipelineService->createExternalId($targetPlatform, $entityType, $reference);
        }

        return [
            'entity_type' => $entityType,
            'operation' => $operation,
            'target_id' => $targetId,
            'changed_fields' => $action['changed_fields'] ?? [],
            'fields' => $action['fields'] ?? [],
        ];
    }

    private function resolveOdooService(string $targetPlatform): ?OdooService
    {
        if ($targetPlatform !== 'odoo') {
            return null;
        }

        $destinationEvent = $this->event->to_event;
        $platform = $destinationEvent?->platform;
        if (! $platform || $platform->type !== 'odoo') {
            return null;
        }

        return app()->make(OdooService::class, [
            'platform' => $platform,
            'event' => $destinationEvent,
            'record' => $this->record,
        ]);
    }

    private function buildMappedPartnerPayload(array $action, string $entityType): array
    {
        $fields = Arr::get($action, 'fields', []);
        if (! is_array($fields)) {
            $fields = [];
        }

        $entity = Arr::get($action, 'entity', []);
        if (! is_array($entity)) {
            $entity = [];
        }

        $hubspotId = Arr::get($entity, 'hubspot_id', Arr::get($entity, 'id'));
        if (is_scalar($hubspotId) && trim((string) $hubspotId) !== '') {
            $fields['hs_object_id'] ??= (string) $hubspotId;
            $fields['hubspot_object_id'] = (string) $hubspotId;
        }

        $payload = [
            'hubspot_object_id' => $fields['hubspot_object_id'] ?? null,
        ];

        foreach ($this->event->propertyRelationships as $relationship) {
            if (! $relationship instanceof PropertyRelationship || ! $relationship->active) {
                continue;
            }

            $sourceKey = $relationship->mapping_key
                ?: ($relationship->property?->key ?: $relationship->property?->name);
            $targetKey = $relationship->relatedProperty?->key ?: $relationship->relatedProperty?->name;

            if (! is_string($sourceKey) || ! is_string($targetKey)) {
                continue;
            }

            $prefix = $entityType.'.';
            if (! str_starts_with($sourceKey, $prefix)) {
                continue;
            }

            $fieldKey = substr($sourceKey, strlen($prefix));
            if ($fieldKey === '') {
                continue;
            }

            if ($this->isTechnicalSyncField($fieldKey) || $this->isInvalidPartnerPayloadTarget($targetKey)) {
                continue;
            }

            $value = data_get($fields, $fieldKey);
            if ($value === null || $value === '') {
                continue;
            }

            if ($this->isFileRelationship($relationship)) {
                $value = app(HubspotFilePropertyService::class)->normalizeFileValueForOdoo($value, $targetKey);
                if ($value === null) {
                    continue;
                }
            }

            data_set($payload, $targetKey, $value);
        }

        foreach ($fields as $key => $value) {
            if (! is_string($key) || $value === null || $value === '' || array_key_exists($key, $payload)) {
                continue;
            }

            if (in_array($key, ['hubspot_object_id', 'parent_id'], true) && is_scalar($value)) {
                $payload[$key] = $value;
            }
        }

        return array_filter($payload, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function isFileRelationship(PropertyRelationship $relationship): bool
    {
        return $relationship->property?->type === 'file'
            || $relationship->relatedProperty?->type === 'file';
    }

    private function isTechnicalSyncField(string $field): bool
    {
        return in_array($field, [
            'odoo_id',
            'sync_status_odoo',
            'last_sync_odoo',
            'last_error_odoo',
            'sync_to_odoo',
        ], true);
    }

    private function isInvalidPartnerPayloadTarget(string $target): bool
    {
        return in_array($target, [
            'partner_id',
            'partner_invoice_id',
            'partner_shipping_id',
        ], true);
    }

    private function writeBackPartnerIdsToHubspot(array $processedQuote, HubspotApiServiceRefactored $hubspotApi): array
    {
        $results = [];

        $results['company'] = $this->writeBackOneEntityToHubspot(
            Arr::get($processedQuote, 'entity_results.company.output_payload'),
            'companies',
            $hubspotApi
        );

        $contactResults = [];
        foreach ($this->contactResults($processedQuote) as $contactResult) {
            $contactResults[] = $this->writeBackOneEntityToHubspot(
                Arr::get($contactResult, 'output_payload'),
                'contacts',
                $hubspotApi
            );
        }

        $results['contacts'] = $contactResults;
        $results['contact'] = $contactResults[0] ?? [
            'attempted' => false,
            'reason' => 'missing_output_payload',
        ];

        return [
            'quote_id' => $processedQuote['quote_id'] ?? null,
            'entities' => $results,
        ];
    }

    private function writeBackOneEntityToHubspot(mixed $outputPayload, string $objectType, HubspotApiServiceRefactored $hubspotApi): array
    {
        if (! is_array($outputPayload)) {
            return [
                'attempted' => false,
                'reason' => 'missing_output_payload',
            ];
        }

        $objectId = (string) Arr::get($outputPayload, 'id', '');
        $properties = Arr::get($outputPayload, 'properties', []);
        if ($objectId === '' || ! is_array($properties) || $properties === []) {
            return [
                'attempted' => false,
                'reason' => 'missing_object_id_or_properties',
            ];
        }

        $response = $hubspotApi->updateObject($objectType, $objectId, $properties);

        return [
            'attempted' => true,
            'success' => (bool) ($response['success'] ?? false),
            'object_type' => $objectType,
            'object_id' => $objectId,
            'properties' => array_keys($properties),
            'status_code' => $response['status_code'] ?? null,
            'error' => $response['error'] ?? null,
        ];
    }

    private function partnerBlockReason(array $processedQuote, array $writeback): ?string
    {
        foreach (['company'] as $entityKey) {
            $operation = Arr::get($processedQuote, 'entity_results.'.$entityKey.'.operation');
            $targetId = Arr::get($processedQuote, 'entity_results.'.$entityKey.'.target_id');

            if ($operation === 'error' || ! is_numeric($targetId)) {
                return $entityKey.'_odoo_upsert_failed';
            }

            $writebackAttempted = Arr::get($writeback, 'entities.'.$entityKey.'.attempted');
            $writebackSuccess = Arr::get($writeback, 'entities.'.$entityKey.'.success');
            if ($writebackAttempted !== true || $writebackSuccess !== true) {
                return $entityKey.'_hubspot_writeback_failed';
            }
        }

        foreach ($this->contactResults($processedQuote) as $index => $contact) {
            $operation = Arr::get($contact, 'operation');
            $targetId = Arr::get($contact, 'target_id');
            if ($operation === 'error' || ! is_numeric($targetId)) {
                return 'contact_'.($index + 1).'_odoo_upsert_failed';
            }

            $writebackAttempted = Arr::get($writeback, 'entities.contacts.'.$index.'.attempted');
            $writebackSuccess = Arr::get($writeback, 'entities.contacts.'.$index.'.success');
            if ($writebackAttempted !== true || $writebackSuccess !== true) {
                return 'contact_'.($index + 1).'_hubspot_writeback_failed';
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contactResults(array $processedQuote): array
    {
        $contacts = Arr::get($processedQuote, 'entity_results.contacts', []);
        if (is_array($contacts) && $contacts !== []) {
            return array_values(array_filter($contacts, 'is_array'));
        }

        $contact = Arr::get($processedQuote, 'entity_results.contact');

        return is_array($contact) && $contact !== [] ? [$contact] : [];
    }

    private function firstScalar(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class SignedQuotesPipelineService
{
    /**
     * @param  array<int, array<string, mixed>>  $quotes
     * @return array<int, array<string, mixed>>
     */
    public function normalizeQuotes(array $quotes): array
    {
        $normalized = [];

        foreach ($quotes as $index => $quote) {
            if (! is_array($quote)) {
                continue;
            }

            $quoteId = (string) ($quote['quote_id'] ?? $quote['id'] ?? ('quote_'.($index + 1)));
            $entities = Arr::get($quote, 'entities', []);
            $properties = Arr::get($quote, 'properties', []);
            if (! is_array($properties)) {
                $properties = [];
            }

            $normalized[] = [
                'quote_id' => $quoteId,
                'hubspot_quote_id' => Arr::get($quote, 'hubspot_quote_id', Arr::get($quote, 'id')),
                'status' => Arr::get($quote, 'status', 'signed'),
                'sync_status_odoo' => Arr::get($quote, 'sync_status_odoo', Arr::get($properties, 'sync_status_odoo')),
                'odoo_id' => Arr::get($quote, 'odoo_id', Arr::get($properties, 'odoo_id')),
                'last_sync_odoo' => Arr::get($quote, 'last_sync_odoo', Arr::get($properties, 'last_sync_odoo')),
                'last_error_odoo' => Arr::get($quote, 'last_error_odoo', Arr::get($properties, 'last_error_odoo')),
                'entities' => [
                    'company' => $this->normalizeEntity(Arr::get($entities, 'company', Arr::get($quote, 'company', []))),
                    'contact' => $this->normalizeEntity(Arr::get($entities, 'contact', Arr::get($quote, 'contact', []))),
                    'products' => $this->normalizeProducts(Arr::get($entities, 'products', Arr::get($quote, 'products', []))),
                ],
                'raw' => $quote,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $quotes
     * @return array<int, array<string, mixed>>
     */
    public function evaluateEntities(array $quotes, string $targetPlatform): array
    {
        return array_map(function (array $quote) use ($targetPlatform): array {
            $company = $this->evaluateEntity(
                Arr::get($quote, 'entities.company', []),
                $targetPlatform,
                'company'
            );
            $contact = $this->evaluateEntity(
                Arr::get($quote, 'entities.contact', []),
                $targetPlatform,
                'contact'
            );

            $products = [];
            foreach (Arr::get($quote, 'entities.products', []) as $product) {
                $products[] = $this->evaluateEntity($product, $targetPlatform, 'product');
            }

            return [
                'quote_id' => $quote['quote_id'],
                'hubspot_quote_id' => $quote['hubspot_quote_id'] ?? null,
                'sync_status_odoo' => $quote['sync_status_odoo'] ?? null,
                'odoo_id' => $quote['odoo_id'] ?? null,
                'last_sync_odoo' => $quote['last_sync_odoo'] ?? null,
                'last_error_odoo' => $quote['last_error_odoo'] ?? null,
                'status' => $quote['status'] ?? 'signed',
                'entity_actions' => [
                    'company' => $company,
                    'contact' => $contact,
                    'products' => $products,
                ],
                'raw' => $quote['raw'] ?? [],
            ];
        }, $quotes);
    }

    public function buildHubspotSyncMetadata(array $entityAction, string $targetPlatform): array
    {
        $operation = $entityAction['action'] ?? 'no_change';
        $updatedFields = $entityAction['changed_fields'] ?? [];

        return [
            'last_sync_'.$targetPlatform => now()->toISOString(),
            'sync_operation_'.$targetPlatform => $operation,
            'updated_fields_'.$targetPlatform => $updatedFields,
        ];
    }

    /**
     * @param  array<string, mixed>  $quote
     * @param  array<string, mixed>  $executionResponse
     * @return array<string, scalar|null>
     */
    public function buildHubspotQuoteSyncProperties(
        array $quote,
        string $targetPlatform,
        array $executionResponse = []
    ): array {
        $operations = [];
        $updatedFields = [];

        foreach ($this->extractQuoteMetadataGroups($quote) as $metadata) {
            $operation = Arr::get($metadata, 'sync_operation_'.$targetPlatform);
            if (is_string($operation) && $operation !== '') {
                $operations[] = $operation;
            }

            foreach ((array) Arr::get($metadata, 'updated_fields_'.$targetPlatform, []) as $field) {
                if (is_scalar($field) && trim((string) $field) !== '') {
                    $updatedFields[] = (string) $field;
                }
            }
        }

        $properties = [
            'last_sync_'.$targetPlatform => now()->toISOString(),
            'sync_status_'.$targetPlatform => Arr::get($executionResponse, 'sync_status', 'success'),
            'last_error_'.$targetPlatform => Arr::get($executionResponse, 'last_error', ''),
            'sync_operation_'.$targetPlatform => $this->resolveAggregateOperation($operations),
            'updated_fields_'.$targetPlatform => json_encode(array_values(array_unique($updatedFields)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $externalId = Arr::get($executionResponse, 'odoo_id', Arr::get($executionResponse, 'external_id'));
        if (is_scalar($externalId) && trim((string) $externalId) !== '') {
            $properties[$targetPlatform.'_id'] = is_numeric($externalId) ? (int) $externalId : (string) $externalId;
        }

        return $properties;
    }

    public function createExternalId(string $targetPlatform, string $entityType, string $reference): string
    {
        $suffix = Str::of($reference)->replaceMatches('/[^a-zA-Z0-9_\-]/', '')->lower();

        return sprintf('%s_%s_%s', $targetPlatform, $entityType, $suffix ?: Str::random(6));
    }

    private function normalizeEntity(mixed $entity): array
    {
        if (! is_array($entity)) {
            return [];
        }

        $fields = Arr::get($entity, 'fields', []);
        if (! is_array($fields)) {
            $fields = [];
        }

        return [
            'id' => Arr::get($entity, 'id'),
            'hubspot_id' => Arr::get($entity, 'hubspot_id'),
            'odoo_id' => Arr::get($entity, 'odoo_id'),
            'netsuite_id' => Arr::get($entity, 'netsuite_id'),
            'fields' => $fields,
            'sync_snapshots' => Arr::get($entity, 'sync_snapshots', []),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeProducts(mixed $products): array
    {
        if (! is_array($products)) {
            return [];
        }

        $normalized = [];
        foreach ($products as $product) {
            $normalized[] = $this->normalizeEntity($product);
        }

        return $normalized;
    }

    private function evaluateEntity(array $entity, string $targetPlatform, string $entityType): array
    {
        $targetId = Arr::get($entity, $targetPlatform.'_id');
        $fields = Arr::get($entity, 'fields', []);
        if (! is_array($fields)) {
            $fields = [];
        }

        $snapshot = Arr::get($entity, 'sync_snapshots.'.$targetPlatform, []);
        if (! is_array($snapshot)) {
            $snapshot = [];
        }

        $changedFields = $this->detectChangedFields($fields, $snapshot);

        if (! $targetId) {
            $action = 'create';
        } elseif (! empty($changedFields)) {
            $action = 'update';
        } else {
            $action = 'no_change';
        }

        return [
            'entity_type' => $entityType,
            'entity' => $entity,
            'target_id' => $targetId,
            'action' => $action,
            'changed_fields' => $changedFields,
            'fields' => $fields,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $snapshot
     * @return array<int, string>
     */
    private function detectChangedFields(array $fields, array $snapshot): array
    {
        $changed = [];
        foreach ($fields as $key => $value) {
            $snapshotValue = $snapshot[$key] ?? null;
            if ($snapshotValue !== $value) {
                $changed[] = (string) $key;
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<int, array<string, mixed>>
     */
    private function extractQuoteMetadataGroups(array $quote): array
    {
        $groups = [];

        foreach (['company', 'contact'] as $entityKey) {
            $metadata = Arr::get($quote, 'hubspot_sync_metadata.'.$entityKey, []);
            if (is_array($metadata) && $metadata !== []) {
                $groups[] = $metadata;
            }
        }

        foreach ((array) Arr::get($quote, 'hubspot_sync_metadata.products', []) as $metadata) {
            if (is_array($metadata) && $metadata !== []) {
                $groups[] = $metadata;
            }
        }

        return $groups;
    }

    /**
     * @param  array<int, string>  $operations
     */
    private function resolveAggregateOperation(array $operations): string
    {
        $operations = array_values(array_unique(array_filter($operations, static fn (mixed $operation): bool => is_string($operation) && $operation !== '')));

        if ($operations === []) {
            return 'no_change';
        }

        if (count($operations) === 1) {
            return $operations[0];
        }

        return 'mixed';
    }
}

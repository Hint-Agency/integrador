<?php

namespace App\Services\Hubspot;

use App\Jobs\ProcessSignedQuotesJob;
use App\Models\Event;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use App\Models\Record;
use App\Services\Base\BaseService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class HubspotService extends BaseService
{
    public function __construct(
        Platform $platform,
        ?Event $event = null,
        ?Record $record = null,
        protected ?HubspotApiServiceRefactored $hubspotApi = null,
        protected ?ProductCacheService $productCacheService = null
    ) {
        parent::__construct($platform, $event, $record);
        $this->applyPlatformConfiguration();

        $this->hubspotApi ??= app(HubspotApiServiceRefactored::class);
        $this->productCacheService ??= app(ProductCacheService::class);
    }

    public function companyCreatedWebhook(array $payload): array
    {
        return $this->success('Company created webhook processed.', [
            'company_id' => $payload['objectId'] ?? Arr::get($payload, 'company.id'),
            'payload' => $payload,
        ]);
    }

    public function contactCreatedWebhook(array $payload): array
    {
        return $this->success('Contact created webhook processed.', [
            'contact_id' => $payload['objectId'] ?? Arr::get($payload, 'contact.id'),
            'payload' => $payload,
        ]);
    }

    public function dealPropertyChange(string $subscriptionType, array $payload, $record): array
    {
        return $this->success('Deal property change received.', $this->buildPropertyChangePayload($subscriptionType, $payload));
    }

    public function contactPropertyChange(string $subscriptionType, array $payload, $record): array
    {
        return $this->success('Contact property change received.', $this->buildPropertyChangePayload($subscriptionType, $payload));
    }

    public function companyPropertyChange(string $subscriptionType, array $payload, $record): array
    {
        return $this->success('Company property change received.', $this->buildPropertyChangePayload($subscriptionType, $payload));
    }

    public function objectPropertyChange(string $subscriptionType, array $payload, $record): array
    {
        return $this->success('Object property change received.', $this->buildPropertyChangePayload($subscriptionType, $payload));
    }

    public function invoicePropertyChange(string $subscriptionType, array $payload, $record): array
    {
        return $this->success('Invoice property change received.', $this->buildPropertyChangePayload($subscriptionType, $payload));
    }

    public function createProducts(array $products): array
    {
        if (empty($products)) {
            return $this->success('No products received for creation.', [
                'count' => 0,
                'created_count' => 0,
                'error_count' => 0,
                'errors' => [],
                'output_payload' => [],
            ]);
        }

        $created = [];
        $errors = [];
        $outputPayload = [];

        foreach ($products as $index => $product) {
            if (! is_array($product)) {
                $errors[] = [
                    'index' => $index,
                    'error' => 'Invalid product payload.',
                ];

                continue;
            }

            $validation = $this->validateProductPayload($product);
            if ($validation !== null) {
                $errors[] = [
                    'index' => $index,
                    'error' => $validation,
                ];

                continue;
            }

            $payload = $this->sanitizeProductPayload($product);
            $properties = $this->hubspotProductProperties($payload);
            $result = $this->hubspotApi->createProduct($properties);

            if (! $result['success']) {
                $errors[] = [
                    'index' => $index,
                    'error' => $result['error'] ?? $result['message'] ?? 'Unknown error',
                    'attempted_properties' => $properties,
                ];

                continue;
            }

            $created[] = $result['data'];
            $outputPayload[] = array_merge($properties, [
                'hubspot_id' => Arr::get($result, 'data.id'),
            ]);
        }

        if (! empty($created)) {
            $this->productCacheService->preload($products);
        }

        return [
            'success' => empty($errors) || ! empty($created),
            'status' => empty($errors) ? null : 'warning',
            'message' => empty($errors)
                ? 'Products created successfully in HubSpot.'
                : (! empty($created) ? 'Some products failed to create in HubSpot.' : 'Products could not be created in HubSpot.'),
            'data' => [
                'created_count' => count($created),
                'error_count' => count($errors),
                'errors' => $errors,
                'output_payload' => $outputPayload,
            ],
        ];
    }

    public function updateProducts(array $updateProducts): array
    {
        if (empty($updateProducts)) {
            return $this->success('No products received for update.', [
                'count' => 0,
                'updated_count' => 0,
                'error_count' => 0,
                'not_found_count' => 0,
                'errors' => [],
                'not_found' => [],
                'output_payload' => [],
            ]);
        }

        $updated = [];
        $errors = [];
        $notFound = [];
        $nextEventConfigured = $this->event?->to_event_id !== null;

        foreach ($updateProducts as $index => $product) {
            if (! is_array($product)) {
                $errors[] = ['index' => $index, 'error' => 'Invalid product payload.'];

                continue;
            }

            $validation = $this->validateProductPayload($product);
            if ($validation !== null) {
                $errors[] = [
                    'index' => $index,
                    'error' => $validation,
                ];

                continue;
            }

            $payload = $this->sanitizeProductPayload($product);
            $productId = $this->resolveExplicitHubspotProductId($payload);
            if ($productId === '') {
                $resolved = $this->resolveHubspotProductId($payload);

                if (($resolved['match_status'] ?? null) === 'not_found') {
                    $notFound[] = $payload;

                    continue;
                }

                if (($resolved['success'] ?? false) !== true) {
                    $errors[] = [
                        'index' => $index,
                        'error' => $resolved['message'] ?? 'Unable to resolve HubSpot product id.',
                        'details' => $resolved['error'] ?? null,
                        'criteria_attempted' => $resolved['criteria_attempted'] ?? [],
                    ];

                    continue;
                }

                $productId = (string) ($resolved['hubspot_id'] ?? '');
            }

            if ($productId === '') {
                $errors[] = ['index' => $index, 'error' => 'Missing HubSpot product id.'];

                continue;
            }

            $properties = $this->hubspotProductProperties($payload);

            $result = $this->hubspotApi->updateProduct($productId, $properties);
            if (! $result['success']) {
                $errors[] = [
                    'index' => $index,
                    'product_id' => $productId,
                    'error' => $result['error'] ?? $result['message'] ?? 'Unknown error',
                    'attempted_properties' => $properties,
                ];

                continue;
            }

            $updated[] = array_merge($properties, [
                'hubspot_id' => $productId,
                'hubspot_response_id' => Arr::get($result, 'data.id'),
            ]);
        }

        if (! empty($updated)) {
            $this->productCacheService->preload($updateProducts);
        }

        $warningReason = null;
        if (! empty($notFound) && ! $nextEventConfigured) {
            $warningReason = 'missing_create_fallback_event';
        }

        return [
            'success' => empty($errors) || ! empty($updated) || ! empty($notFound),
            'status' => (! empty($errors) || $warningReason !== null) ? 'warning' : null,
            'message' => $this->buildProductUpdateMessage($updated, $notFound, $errors, $warningReason),
            'data' => [
                'updated_count' => count($updated),
                'error_count' => count($errors),
                'not_found_count' => count($notFound),
                'errors' => $errors,
                'not_found' => $notFound,
                'warning_reason' => $warningReason,
                'output_payload' => $notFound,
            ],
        ];
    }

    private function resolveHubspotProductId(array $product): array
    {
        $criteria = [
            ['property' => 'odoo_id', 'value' => $product['odoo_id'] ?? $this->resolveOdooProductTemplateId($product) ?? $product['id'] ?? null],
            ['property' => 'identificador_db', 'value' => $product['identificador_db'] ?? null],
            ['property' => 'hs_sku', 'value' => $product['hs_sku'] ?? null],
            ['property' => 'sku', 'value' => $product['sku'] ?? $product['default_code'] ?? null],
        ];

        $attempted = [];

        foreach ($criteria as $criterion) {
            $property = trim((string) ($criterion['property'] ?? ''));
            $value = $criterion['value'] ?? null;

            if ($property === '' || ! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $attempted[] = [
                'property' => $property,
                'value' => trim((string) $value),
            ];

            $response = $this->hubspotApi->searchObjectByProperty('products', $property, (string) $value, [$property]);

            if (! ($response['success'] ?? false)) {
                return [
                    'success' => false,
                    'match_status' => 'failed',
                    'message' => 'HubSpot product search failed.',
                    'criteria_attempted' => $attempted,
                    'error' => $response['error'] ?? null,
                ];
            }

            $results = Arr::get($response, 'data.results', []);
            if (! is_array($results) || count($results) === 0) {
                continue;
            }

            if (count($results) > 1) {
                return [
                    'success' => false,
                    'match_status' => 'failed',
                    'message' => 'Multiple HubSpot products matched the provided identifiers.',
                    'criteria_attempted' => $attempted,
                ];
            }

            return [
                'success' => true,
                'match_status' => 'matched',
                'hubspot_id' => (string) Arr::get($results, '0.id'),
                'criteria_attempted' => $attempted,
            ];
        }

        return [
            'success' => false,
            'match_status' => 'not_found',
            'message' => 'HubSpot product not found using configured match criteria.',
            'criteria_attempted' => $attempted,
        ];
    }

    private function resolveExplicitHubspotProductId(array $payload): string
    {
        if (isset($payload['hubspot_id']) && is_scalar($payload['hubspot_id'])) {
            return trim((string) $payload['hubspot_id']);
        }

        $id = $payload['id'] ?? null;
        if (! is_scalar($id) || trim((string) $id) === '') {
            return '';
        }

        $hasOdooShape = isset($payload['odoo_id'])
            || isset($payload['default_code'])
            || isset($payload['product_tmpl_id'])
            || isset($payload['list_prices'])
            || (isset($payload['_event_metadata']['source_platform']) && strtolower((string) $payload['_event_metadata']['source_platform']) === 'odoo');

        return $hasOdooShape ? '' : trim((string) $id);
    }

    private function sanitizeProductPayload(array $product): array
    {
        unset($product['_event_metadata']);

        if (! isset($product['sku']) && isset($product['default_code']) && is_scalar($product['default_code'])) {
            $product['sku'] = (string) $product['default_code'];
        }

        $hasOdooShape = isset($product['default_code'])
            || isset($product['product_tmpl_id'])
            || isset($product['list_prices'])
            || (isset($product['_event_metadata']['source_platform']) && strtolower((string) $product['_event_metadata']['source_platform']) === 'odoo');

        if ($hasOdooShape) {
            $productTemplateId = $this->resolveOdooProductTemplateId($product);
            if ($productTemplateId !== null) {
                $variantId = $product['id'] ?? $product['odoo_product_id'] ?? $product['odoo_id'] ?? null;
                if (is_scalar($variantId) && trim((string) $variantId) !== '' && (string) $variantId !== (string) $productTemplateId) {
                    $product['odoo_product_id'] = (string) $variantId;
                }

                $product['odoo_id'] = (string) $productTemplateId;
            } elseif (! isset($product['odoo_id']) && isset($product['id']) && is_scalar($product['id'])) {
                $product['odoo_id'] = (string) $product['id'];
            }

            unset($product['id']);
        }

        return $product;
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    private function hubspotProductProperties(array $payload): array
    {
        $properties = [];
        $mappedKeys = $this->mappedHubspotProductPropertyKeys();

        foreach ($payload as $key => $value) {
            if (! is_string($key) || in_array($key, ['id', 'hubspot_id', '_event_metadata'], true)) {
                continue;
            }

            if ($mappedKeys !== [] && ! in_array($key, $mappedKeys, true)) {
                continue;
            }

            if (! is_scalar($value) || $value === '') {
                continue;
            }

            $properties[$key] = $value;
        }

        return $properties;
    }

    /**
     * @return list<string>
     */
    private function mappedHubspotProductPropertyKeys(): array
    {
        $events = [];

        if ($this->event) {
            $events[] = $this->event;
        }

        $record = $this->record;
        while ($record) {
            $record->loadMissing('event.propertyRelationships.relatedProperty', 'parent');
            if ($record->event) {
                $events[] = $record->event;
            }

            $record = $record->parent;
        }

        $keys = [];
        foreach ($events as $event) {
            $event->loadMissing('propertyRelationships.relatedProperty');
            foreach ($event->propertyRelationships as $relationship) {
                if (! $relationship->active) {
                    continue;
                }

                $relatedProperty = $relationship->relatedProperty;
                if (! $relatedProperty || (int) $relatedProperty->platform_id !== (int) $this->platform->id) {
                    continue;
                }

                $key = $relatedProperty->key ?: $relatedProperty->name;
                if (is_string($key) && trim($key) !== '') {
                    $keys[] = trim($key);
                }
            }
        }

        return array_values(array_unique($keys));
    }

    private function resolveOdooProductTemplateId(array $product): ?string
    {
        $template = $product['product_tmpl_id'] ?? $product['product_template_id'] ?? null;

        if (is_array($template)) {
            $template = $template[0] ?? null;
        }

        if (! is_scalar($template) || trim((string) $template) === '') {
            return null;
        }

        return trim((string) $template);
    }

    private function validateProductPayload(array $product): ?string
    {
        $identifier = trim((string) ($product['identificador_db'] ?? ''));
        $sku = trim((string) ($product['sku'] ?? $product['default_code'] ?? ''));
        $odooId = trim((string) ($product['odoo_id'] ?? $product['id'] ?? ''));

        if ($identifier === '' && $sku === '' && $odooId === '') {
            return 'Product payload requires identificador_db, sku/default_code, or odoo_id.';
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $updated
     * @param  list<array<string, mixed>>  $notFound
     * @param  list<array<string, mixed>>  $errors
     */
    private function buildProductUpdateMessage(array $updated, array $notFound, array $errors, ?string $warningReason): string
    {
        if ($warningReason === 'missing_create_fallback_event') {
            return 'Some products were not found in HubSpot and no creation fallback event is configured.';
        }

        if (! empty($errors) && ! empty($updated)) {
            return 'Some products failed to update in HubSpot.';
        }

        if (! empty($errors) && empty($updated) && empty($notFound)) {
            return 'Products could not be updated in HubSpot.';
        }

        if (! empty($notFound)) {
            return 'Products prepared for creation fallback.';
        }

        return 'Products updated successfully in HubSpot.';
    }

    public function getSignedQuotes(): array
    {
        $quotes = $this->event?->meta['signed_quotes_sample']
            ?? $this->record?->payload['quotes']
            ?? null;
        $source = 'payload';
        if ($quotes === null) {
            $remote = $this->hubspotApi->searchSignedQuotes($this->event);
            if ($remote['success']) {
                $quotes = Arr::get($remote, 'data.results', []);
                $source = 'hubspot_search';
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to fetch signed quotes from HubSpot.',
                    'data' => [
                        'error' => $remote['error'] ?? null,
                        'status_code' => $remote['status_code'] ?? null,
                    ],
                ];
            }
        }

        if (! is_array($quotes) || empty($quotes)) {
            return $this->success('No signed quotes found in HubSpot.', [
                'count' => 0,
                'quotes' => [],
                'source' => $source,
                'output_payload' => [],
            ]);
        }

        $queued = 0;
        if ($this->record) {
            foreach (array_values($quotes) as $quote) {
                if (! is_array($quote)) {
                    continue;
                }

                ProcessSignedQuotesJob::dispatch([$quote], $this->event, $this->record)->onQueue('signed-quotes');
                $queued++;
            }
        }

        return $this->success('Signed quotes queued.', [
            'count' => count($quotes),
            'queued_count' => $queued,
            'source' => $source,
            'quotes' => $quotes,
            'output_payload' => [],
        ]);
    }

    public function getArchivedQuotes(): array
    {
        $quotes = [];
        $after = null;
        $pages = 0;
        $properties = $this->archivedQuoteProperties();

        do {
            $query = [
                'archived' => true,
                'limit' => 100,
                'properties' => implode(',', $properties),
            ];

            if ($after !== null) {
                $query['after'] = $after;
            }

            $response = $this->hubspotApi->request('GET', '/crm/v3/objects/quotes', [], $query);

            if (! $response['success']) {
                return [
                    'success' => false,
                    'message' => 'Failed to fetch archived quotes from HubSpot.',
                    'data' => [
                        'error' => $response['error'] ?? null,
                        'pages' => $pages,
                    ],
                ];
            }

            $pages++;
            $results = Arr::get($response, 'data.results', []);
            if (is_array($results)) {
                $quotes = array_merge($quotes, array_filter($results, 'is_array'));
            }

            $after = Arr::get($response, 'data.paging.next.after');
        } while (is_scalar($after) && trim((string) $after) !== '');

        $syncedStatuses = $this->archivedQuoteSyncedStatuses();
        $cancelableQuotes = array_values(array_filter(
            $quotes,
            fn (array $quote): bool => in_array(
                strtolower(trim((string) Arr::get($quote, 'properties.sync_status_odoo', ''))),
                $syncedStatuses,
                true
            )
        ));

        return $this->success('Archived quotes fetched from HubSpot.', [
            'count' => count($cancelableQuotes),
            'archived_count' => count($quotes),
            'filtered_count' => count($quotes) - count($cancelableQuotes),
            'pages' => $pages,
            'required_sync_statuses' => $syncedStatuses,
            'quotes' => $cancelableQuotes,
            'output_payload' => [
                'quotes' => $cancelableQuotes,
            ],
        ]);
    }

    public function createInvoice(): mixed
    {
        $payload = $this->resolvePayloadFromContext();
        $response = $this->hubspotApi->createInvoice($payload);

        if (! $response['success']) {
            return [
                'success' => false,
                'message' => 'Failed to create invoice in HubSpot.',
                'data' => [
                    'error' => $response['error'] ?? null,
                ],
            ];
        }

        return $this->success('Invoice created in HubSpot.', $response['data']);
    }

    public function createObject(): mixed
    {
        $payload = $this->resolvePayloadFromContext();
        $objectType = $this->resolveObjectType('companies');
        $response = $this->hubspotApi->createObject($objectType, $payload);

        if (! $response['success']) {
            $noteResult = $this->tryLogContactFailureNote(
                $objectType,
                (string) ($payload['id'] ?? $payload['hubspot_id'] ?? $payload['objectId'] ?? ''),
                'Failed to create contact in HubSpot.',
                $response
            );

            return [
                'success' => false,
                'message' => 'Failed to create object in HubSpot.',
                'data' => [
                    'error' => $response['error'] ?? null,
                    'hubspot_note' => $noteResult,
                ],
            ];
        }

        return $this->success('Object created in HubSpot.', $response['data']);
    }

    public function updateObject(): array
    {
        if (! $this->record) {
            return [
                'success' => false,
                'message' => 'Record context is required for HubSpot object update.',
                'data' => [],
            ];
        }

        $payload = $this->resolvePayloadFromContext();
        $originalPayload = $payload;
        $objectType = $this->resolveObjectType('companies');
        $objectId = (string) ($payload['id'] ?? $payload['hubspot_id'] ?? '');
        $properties = Arr::get($payload, 'properties');

        if ($objectId === '') {
            return [
                'success' => false,
                'message' => 'Missing HubSpot object id for update.',
                'data' => [],
            ];
        }

        if (is_array($properties)) {
            $payload = $properties;
        } else {
            unset($payload['id'], $payload['hubspot_id']);
        }

        $response = $this->hubspotApi->updateObject($objectType, $objectId, $payload);
        $quoteDealNote = $this->tryLogQuoteSyncDealNote($objectType, $objectId, $originalPayload, $payload, $response);

        if (! $response['success']) {
            $noteResult = $this->tryLogContactFailureNote(
                $objectType,
                $objectId,
                'Failed to update contact in HubSpot.',
                $response
            );

            return [
                'success' => false,
                'message' => 'Failed to update object in HubSpot.',
                'data' => [
                    'error' => $response['error'] ?? null,
                    'hubspot_note' => $noteResult,
                    'hubspot_deal_note' => $quoteDealNote,
                ],
            ];
        }

        return $this->success('Object updated in HubSpot.', [
            'data' => $response['data'] ?? [],
            'hubspot_deal_note' => $quoteDealNote,
        ]);
    }

    public function updateQuoteObject(): array
    {
        if (! $this->record) {
            return [
                'success' => false,
                'message' => 'Record context is required for HubSpot quote update.',
                'data' => [
                    'reason' => 'missing_record_context',
                ],
            ];
        }

        $payload = $this->resolvePayloadFromContext();
        $items = $this->normalizeQuoteWritebackPayloads($payload);
        $objectType = $this->resolveObjectType('quotes');
        $acceptedIdFields = ['id', 'hubspot_id', 'hubspot_quote_id', 'quote_id', 'hs_object_id', 'properties.hs_object_id'];

        if ($items === []) {
            return [
                'success' => false,
                'message' => 'No HubSpot quote payloads found for update.',
                'data' => [
                    'reason' => 'empty_quote_writeback_payload',
                    'object_type' => $objectType,
                    'accepted_id_fields' => $acceptedIdFields,
                ],
            ];
        }

        $results = [];
        $errors = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $errors[] = [
                    'index' => $index,
                    'reason' => 'invalid_quote_payload',
                    'object_type' => $objectType,
                    'payload_type' => get_debug_type($item),
                ];

                continue;
            }

            $objectId = $this->firstScalar([
                Arr::get($item, 'id'),
                Arr::get($item, 'hubspot_id'),
                Arr::get($item, 'hubspot_quote_id'),
                Arr::get($item, 'quote_id'),
                Arr::get($item, 'hs_object_id'),
                Arr::get($item, 'properties.hs_object_id'),
            ]);
            $properties = Arr::get($item, 'properties');

            if ($objectId === null) {
                $errors[] = [
                    'index' => $index,
                    'reason' => 'missing_hubspot_quote_id',
                    'object_type' => $objectType,
                    'accepted_id_fields' => $acceptedIdFields,
                    'payload_keys' => array_keys($item),
                ];

                continue;
            }

            if (! is_array($properties) || $properties === []) {
                $errors[] = [
                    'index' => $index,
                    'reason' => 'missing_quote_writeback_properties',
                    'object_type' => $objectType,
                    'object_id' => $objectId,
                    'payload_keys' => array_keys($item),
                ];

                continue;
            }

            $response = $this->hubspotApi->updateObject($objectType, $objectId, $properties);
            $quoteDealNote = $this->tryLogQuoteSyncDealNote($objectType, $objectId, $item, $properties, $response);

            $entry = [
                'index' => $index,
                'object_type' => $objectType,
                'object_id' => $objectId,
                'quote_id' => $item['quote_id'] ?? $item['hubspot_quote_id'] ?? $objectId,
                'hubspot_deal_note' => $quoteDealNote,
                'hubspot_response' => $response['data'] ?? [],
                'status_code' => $response['status_code'] ?? null,
            ];

            if (! ($response['success'] ?? false)) {
                $errors[] = $entry + [
                    'reason' => 'hubspot_quote_update_failed',
                    'error' => $response['error'] ?? null,
                ];

                continue;
            }

            $results[] = $entry;
        }

        $updatedCount = count($results);
        $errorCount = count($errors);
        $data = [
            'object_type' => $objectType,
            'updated_count' => $updatedCount,
            'error_count' => $errorCount,
            'results' => $results,
            'errors' => $errors,
        ];

        if ($updatedCount === 0 && $errorCount > 0) {
            return [
                'success' => false,
                'message' => 'Failed to update HubSpot quote object.',
                'data' => $data,
            ];
        }

        if ($errorCount > 0) {
            return [
                'success' => true,
                'status' => 'warning',
                'message' => 'HubSpot quote write-back completed with warnings.',
                'data' => $data,
            ];
        }

        return $this->success('HubSpot quote object updated.', $data);
    }

    public function writeBackArchivedQuoteCancellation(): array
    {
        if (! $this->record) {
            return [
                'success' => false,
                'message' => 'Record context is required for HubSpot archived quote cancellation write-back.',
                'data' => [
                    'reason' => 'missing_record_context',
                ],
            ];
        }

        $payload = $this->resolvePayloadFromContext();
        $items = $this->normalizeQuoteWritebackPayloads($payload);
        $objectType = $this->resolveObjectType('quotes');

        if ($items === []) {
            return [
                'success' => false,
                'message' => 'No archived quote cancellation payloads found for write-back.',
                'data' => [
                    'reason' => 'empty_archived_quote_cancellation_payload',
                    'object_type' => $objectType,
                ],
            ];
        }

        $results = [];
        $errors = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $errors[] = [
                    'index' => $index,
                    'reason' => 'invalid_quote_payload',
                    'payload_type' => get_debug_type($item),
                ];

                continue;
            }

            $objectId = $this->firstScalar([
                Arr::get($item, 'id'),
                Arr::get($item, 'hubspot_id'),
                Arr::get($item, 'hubspot_quote_id'),
                Arr::get($item, 'quote_id'),
                Arr::get($item, 'hs_object_id'),
                Arr::get($item, 'properties.hs_object_id'),
            ]);
            $properties = Arr::get($item, 'properties');

            if ($objectId === null || ! is_array($properties) || $properties === []) {
                $errors[] = [
                    'index' => $index,
                    'reason' => $objectId === null ? 'missing_hubspot_quote_id' : 'missing_quote_writeback_properties',
                    'payload_keys' => array_keys($item),
                ];

                continue;
            }

            $response = $this->hubspotApi->updateObject($objectType, $objectId, $properties);
            $quoteDealNote = $this->tryLogArchivedQuoteCancellationDealNote($objectType, $objectId, $item, $properties, $response);

            $entry = [
                'index' => $index,
                'object_type' => $objectType,
                'object_id' => $objectId,
                'quote_id' => $item['quote_id'] ?? $item['hubspot_quote_id'] ?? $objectId,
                'hubspot_deal_note' => $quoteDealNote,
                'hubspot_response' => $response['data'] ?? [],
                'status_code' => $response['status_code'] ?? null,
            ];

            if (! ($response['success'] ?? false)) {
                $errors[] = $entry + [
                    'reason' => 'hubspot_archived_quote_update_failed',
                    'error' => $response['error'] ?? null,
                ];

                continue;
            }

            $results[] = $entry;
        }

        $updatedCount = count($results);
        $errorCount = count($errors);
        $data = [
            'object_type' => $objectType,
            'updated_count' => $updatedCount,
            'error_count' => $errorCount,
            'results' => $results,
            'errors' => $errors,
        ];

        if ($updatedCount === 0 && $errorCount > 0) {
            return [
                'success' => false,
                'message' => 'Failed to write back HubSpot archived quote cancellation.',
                'data' => $data,
            ];
        }

        if ($errorCount > 0) {
            return [
                'success' => true,
                'status' => 'warning',
                'message' => 'HubSpot archived quote cancellation write-back completed with warnings.',
                'data' => $data,
            ];
        }

        return $this->success('HubSpot archived quote cancellation written back.', $data);
    }

    public function createOrUpdateInvoiceObject(array $payload): array
    {
        $invoice = Arr::get($payload, 'invoice', $payload);
        if (! is_array($invoice)) {
            return [
                'success' => false,
                'message' => 'Invalid invoice payload for HubSpot invoice object sync.',
                'data' => ['reason' => 'invalid_invoice_payload'],
            ];
        }

        $objectType = (string) ($this->event?->meta['invoice_object_type'] ?? $this->event?->meta['object_type'] ?? 'invoices');
        $idProperty = (string) ($this->event?->meta['invoice_match_property'] ?? 'odoo_id');
        $invoiceId = $this->firstScalar([
            Arr::get($invoice, 'id'),
            Arr::get($invoice, 'odoo_id'),
            Arr::get($payload, 'source.id'),
        ]);

        if ($invoiceId === null) {
            return [
                'success' => false,
                'message' => 'Missing Odoo invoice id for HubSpot invoice object sync.',
                'data' => ['reason' => 'missing_invoice_id'],
            ];
        }

        $properties = $this->buildInvoiceObjectProperties($payload, $invoice, (string) $invoiceId);
        $match = $this->findInvoiceObjectMatch($objectType, $idProperty, (string) $invoiceId, $properties);

        if (! ($match['success'] ?? false)) {
            return [
                'success' => false,
                'message' => 'Failed to search HubSpot invoice object.',
                'data' => [
                    'object_type' => $objectType,
                    'match_property' => $idProperty,
                    'invoice_id' => (string) $invoiceId,
                    'error' => $match['error'] ?? null,
                ],
            ];
        }

        if (($match['status'] ?? null) === 'warning') {
            return [
                'success' => true,
                'status' => 'warning',
                'message' => 'Multiple HubSpot invoice objects matched Odoo invoice id.',
                'data' => [
                    'warning_reason' => 'multiple_invoice_matches',
                    'object_type' => $objectType,
                    'match_property' => $match['match_property'] ?? $idProperty,
                    'invoice_id' => (string) $invoiceId,
                    'matches' => $match['matches'] ?? [],
                ],
            ];
        }

        $operation = 'created';
        $objectId = $match['object_id'] ?? null;
        if (is_scalar($objectId) && trim((string) $objectId) !== '') {
            $objectId = (string) $objectId;
            $response = $this->hubspotApi->updateObject($objectType, $objectId, $properties);
            $operation = 'updated';
        } else {
            $response = $this->hubspotApi->createObject($objectType, $properties);
            $objectId = (string) Arr::get($response, 'data.id', '');
        }

        if (! ($response['success'] ?? false)) {
            return [
                'success' => false,
                'message' => 'Failed to create or update HubSpot invoice object.',
                'data' => [
                    'operation' => $operation,
                    'object_type' => $objectType,
                    'invoice_id' => (string) $invoiceId,
                    'properties' => $properties,
                    'error' => $response['error'] ?? null,
                ],
            ];
        }

        $dealId = $this->resolveInvoiceDealId($payload);
        $warnings = [];
        $association = null;
        if ($dealId && $objectId !== '') {
            $associationTypeId = $this->event?->meta['invoice_to_deal_association_type_id'] ?? null;
            $association = $this->hubspotApi->associateObjects(
                $objectType,
                $objectId,
                'deals',
                $dealId,
                is_numeric($associationTypeId) ? (int) $associationTypeId : null
            );

            if (! ($association['success'] ?? false)) {
                $warnings[] = [
                    'reason' => 'invoice_deal_association_failed',
                    'deal_id' => $dealId,
                    'object_id' => $objectId,
                    'error' => $association['error'] ?? null,
                ];
            }
        } else {
            $warnings[] = [
                'reason' => 'hubspot_deal_id_missing',
                'object_id' => $objectId,
            ];
        }

        $data = [
            'operation' => $operation,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'invoice_id' => (string) $invoiceId,
            'deal_id' => $dealId,
            'properties' => $properties,
            'match' => [
                'property' => $match['match_property'] ?? $idProperty,
                'value' => $match['match_value'] ?? (string) $invoiceId,
            ],
            'hubspot_response' => $response['data'] ?? [],
            'association' => $association,
            'warnings' => $warnings,
        ];

        if ($warnings !== []) {
            return [
                'success' => true,
                'status' => 'warning',
                'message' => 'HubSpot invoice object synchronized with warnings.',
                'data' => $data,
            ];
        }

        return $this->success('HubSpot invoice object synchronized.', $data);
    }

    public function updateCompany(array $payload): array
    {
        $companyId = (string) ($payload['id'] ?? $payload['hubspot_id'] ?? '');
        if ($companyId === '') {
            return [
                'success' => false,
                'message' => 'Missing company id for HubSpot update.',
                'data' => [],
            ];
        }

        $properties = $payload;
        unset($properties['id'], $properties['hubspot_id']);

        $response = $this->hubspotApi->updateObject('companies', $companyId, $properties);
        if (! $response['success']) {
            return [
                'success' => false,
                'message' => 'Failed to update company in HubSpot.',
                'data' => ['error' => $response['error'] ?? null],
            ];
        }

        return $this->success('Company updated in HubSpot.', $response['data']);
    }

    public function syncContactExecutionResponse(array $payload): array
    {
        $contactId = $this->resolveHubspotContactIdFromPayload($payload);
        if ($contactId === null) {
            return [
                'success' => false,
                'message' => 'Missing HubSpot contact id for response write-back.',
                'data' => [
                    'required_context' => [
                        'hubspot_object_id',
                        'hubspot_contact_id',
                        'contact.id',
                        'contact.hubspot_id',
                        'objectId',
                        'id',
                    ],
                    'received_keys' => array_keys($payload),
                    'mapping_event_id' => $this->resolveResponseMappingEventId($payload),
                ],
            ];
        }

        $mappingEventId = $this->resolveResponseMappingEventId($payload);
        $properties = array_merge(
            $this->buildHubspotContactPropertiesFromResponse($mappingEventId, $payload),
            $this->buildPlatformSyncSuccessProperties($payload)
        );

        if ($properties === []) {
            return $this->success('No mapped HubSpot properties found in destination response.', [
                'contact_id' => $contactId,
                'mapping_event_id' => $mappingEventId,
                'destination_response_keys' => $this->extractDestinationResponseKeys($payload),
                'updated_properties' => [],
            ]);
        }

        $response = $this->hubspotApi->updateObject('contacts', $contactId, $properties);
        if (! $response['success']) {
            $noteResult = $this->tryLogContactFailureNote(
                'contacts',
                $contactId,
                'Failed to store destination response in HubSpot contact.',
                $response
            );

            return [
                'success' => false,
                'message' => 'Failed to update HubSpot contact from destination response.',
                'data' => [
                    'error' => $response['error'] ?? null,
                    'hubspot_note' => $noteResult,
                    'contact_id' => $contactId,
                    'mapping_event_id' => $mappingEventId,
                    'attempted_properties' => $properties,
                ],
            ];
        }

        return $this->success('HubSpot contact updated from destination response.', [
            'contact_id' => $contactId,
            'mapping_event_id' => $mappingEventId,
            'updated_properties' => $properties,
            'hubspot_response' => $response['data'] ?? [],
        ]);
    }

    public function syncAspelContactToHubspot(array $payload): array
    {
        $result = $this->updateAspelContactInHubspot($payload);

        $outputPayload = Arr::get($result, 'data.output_payload');
        $nextEventConfigured = $this->event?->to_event_id !== null;
        if (
            ($result['success'] ?? false) === true
            && Arr::get($result, 'status') !== 'warning'
            && is_array($outputPayload)
            && $outputPayload !== []
            && ! $nextEventConfigured
        ) {
            return $this->createAspelContactInHubspot($outputPayload);
        }

        return $result;
    }

    public function updateAspelContactInHubspot(array $payload): array
    {
        [$mappingEventId, $sourceData, $propertiesResult, $preparedError] = $this->prepareAspelHubspotSyncContext($payload);

        if ($preparedError !== null || ($propertiesResult['success'] ?? false) === false) {
            return $propertiesResult;
        }

        $resolvedProperties = $propertiesResult['properties'];
        $matchResult = $this->findHubspotContactForAspelPayload($payload, $sourceData);
        if (! ($matchResult['success'] ?? false)) {
            return $matchResult;
        }

        if (($matchResult['multiple'] ?? false) === true) {
            return [
                'success' => true,
                'status' => 'warning',
                'message' => 'Multiple HubSpot contacts matched ASPEL change.',
                'data' => [
                    'warning_reason' => 'multiple_matches',
                    'mapping_event_id' => $mappingEventId,
                    'output_payload' => [],
                    'matches' => $matchResult['matches'] ?? [],
                    'match_property' => $matchResult['match_property'] ?? null,
                ],
            ];
        }

        if (($matchResult['found'] ?? false) !== true) {
            $nextEventConfigured = $this->event?->to_event_id !== null;
            $warningReason = $nextEventConfigured ? null : 'missing_create_fallback_event';

            return [
                'success' => true,
                'status' => $warningReason ? 'warning' : null,
                'message' => $warningReason
                    ? 'ASPEL contact was not found in HubSpot and no creation fallback event is configured.'
                    : 'ASPEL contact prepared for HubSpot creation fallback.',
                'data' => [
                    'operation' => 'not_found',
                    'updated_count' => 0,
                    'not_found_count' => 1,
                    'warning_reason' => $warningReason,
                    'mapping_event_id' => $mappingEventId,
                    'output_payload' => $payload,
                ],
            ];
        }

        $contactId = (string) $matchResult['contact_id'];
        $response = $this->hubspotApi->updateObject('contacts', $contactId, $resolvedProperties);

        if (! ($response['success'] ?? false)) {
            $noteResult = $this->tryLogContactFailureNote(
                'contacts',
                $contactId,
                'Failed to update HubSpot contact from ASPEL change.',
                $response
            );

            return [
                'success' => false,
                'message' => 'Failed to update HubSpot contact from ASPEL change.',
                'data' => [
                    'error' => $response['error'] ?? null,
                    'hubspot_note' => $noteResult,
                    'contact_id' => $contactId,
                    'mapping_event_id' => $mappingEventId,
                    'attempted_properties' => $resolvedProperties,
                    'matched_by' => $matchResult['matched_by'] ?? null,
                ],
            ];
        }

        return $this->success('HubSpot contact updated from ASPEL change.', [
            'operation' => 'updated',
            'updated_count' => 1,
            'not_found_count' => 0,
            'contact_id' => $contactId,
            'matched_by' => $matchResult['matched_by'] ?? null,
            'mapping_event_id' => $mappingEventId,
            'updated_properties' => $resolvedProperties,
            'hubspot_response' => $response['data'] ?? [],
            'output_payload' => [],
        ]);
    }

    public function createAspelContactInHubspot(array $payload): array
    {
        [$mappingEventId, $sourceData, $properties, $preparedError] = $this->prepareAspelHubspotSyncContext($payload);

        if ($preparedError !== null) {
            return $preparedError;
        }

        if (($properties['success'] ?? false) === false) {
            return $properties;
        }

        $resolvedProperties = $properties['properties'];

        $response = $this->hubspotApi->createObject('contacts', $resolvedProperties);
        if (! ($response['success'] ?? false)) {
            return [
                'success' => false,
                'message' => 'Failed to create HubSpot contact from ASPEL change.',
                'data' => [
                    'error' => $response['error'] ?? null,
                    'mapping_event_id' => $mappingEventId,
                    'attempted_properties' => $resolvedProperties,
                ],
            ];
        }

        return $this->success('HubSpot contact created from ASPEL change.', [
            'operation' => 'created',
            'updated_count' => 0,
            'not_found_count' => 0,
            'contact_id' => Arr::get($response, 'data.id'),
            'mapping_event_id' => $mappingEventId,
            'created_properties' => $resolvedProperties,
            'hubspot_response' => $response['data'] ?? [],
            'output_payload' => [],
        ]);
    }

    public function updateAspelProductInHubspot(array $payload): array
    {
        [$mappingEventId, $sourceData, $propertiesResult, $preparedError] = $this->prepareAspelHubspotProductSyncContext($payload);

        if ($preparedError !== null || ($propertiesResult['success'] ?? false) === false) {
            return $propertiesResult;
        }

        $resolvedProperties = $propertiesResult['properties'];
        $matchResult = $this->findHubspotProductForAspelPayload($payload, $sourceData);
        if (! ($matchResult['success'] ?? false)) {
            return $matchResult;
        }

        if (($matchResult['multiple'] ?? false) === true) {
            return [
                'success' => true,
                'status' => 'warning',
                'message' => 'Multiple HubSpot products matched ASPEL change.',
                'data' => [
                    'warning_reason' => 'multiple_matches',
                    'mapping_event_id' => $mappingEventId,
                    'output_payload' => [],
                    'matches' => $matchResult['matches'] ?? [],
                    'match_property' => $matchResult['match_property'] ?? null,
                ],
            ];
        }

        if (($matchResult['found'] ?? false) !== true) {
            $nextEventConfigured = $this->event?->to_event_id !== null;
            $warningReason = $nextEventConfigured ? null : 'missing_create_fallback_event';

            return [
                'success' => true,
                'status' => $warningReason ? 'warning' : null,
                'message' => $warningReason
                    ? 'ASPEL product was not found in HubSpot and no creation fallback event is configured.'
                    : 'ASPEL product prepared for HubSpot creation fallback.',
                'data' => [
                    'operation' => 'not_found',
                    'updated_count' => 0,
                    'not_found_count' => 1,
                    'warning_reason' => $warningReason,
                    'mapping_event_id' => $mappingEventId,
                    'output_payload' => $payload,
                ],
            ];
        }

        $productId = (string) $matchResult['product_id'];
        $response = $this->hubspotApi->updateProduct($productId, $resolvedProperties);

        if (! ($response['success'] ?? false)) {
            return [
                'success' => false,
                'message' => 'Failed to update HubSpot product from ASPEL change.',
                'data' => [
                    'error' => $response['error'] ?? null,
                    'product_id' => $productId,
                    'mapping_event_id' => $mappingEventId,
                    'attempted_properties' => $resolvedProperties,
                    'matched_by' => $matchResult['matched_by'] ?? null,
                ],
            ];
        }

        return $this->success('HubSpot product updated from ASPEL change.', [
            'operation' => 'updated',
            'updated_count' => 1,
            'not_found_count' => 0,
            'product_id' => $productId,
            'matched_by' => $matchResult['matched_by'] ?? null,
            'mapping_event_id' => $mappingEventId,
            'updated_properties' => $resolvedProperties,
            'hubspot_response' => $response['data'] ?? [],
            'output_payload' => [],
        ]);
    }

    public function createAspelProductInHubspot(array $payload): array
    {
        [$mappingEventId, $sourceData, $properties, $preparedError] = $this->prepareAspelHubspotProductSyncContext($payload);

        if ($preparedError !== null) {
            return $preparedError;
        }

        if (($properties['success'] ?? false) === false) {
            return $properties;
        }

        $resolvedProperties = $properties['properties'];
        $response = $this->hubspotApi->createProduct($resolvedProperties);

        if (! ($response['success'] ?? false)) {
            return [
                'success' => false,
                'message' => 'Failed to create HubSpot product from ASPEL change.',
                'data' => [
                    'error' => $response['error'] ?? null,
                    'mapping_event_id' => $mappingEventId,
                    'attempted_properties' => $resolvedProperties,
                ],
            ];
        }

        return $this->success('HubSpot product created from ASPEL change.', [
            'operation' => 'created',
            'updated_count' => 0,
            'not_found_count' => 0,
            'product_id' => Arr::get($response, 'data.id'),
            'mapping_event_id' => $mappingEventId,
            'created_properties' => $resolvedProperties,
            'hubspot_response' => $response['data'] ?? [],
            'output_payload' => [],
        ]);
    }

    public function testConnection(): array
    {
        $token = config('hubspot.access_token');
        if (! $token) {
            return [
                'success' => false,
                'message' => 'HubSpot access token is not configured.',
                'data' => [
                    'configured' => false,
                ],
            ];
        }

        $ping = $this->hubspotApi->ping();
        if (! $ping['success']) {
            return [
                'success' => false,
                'message' => 'HubSpot token configured but validation request failed.',
                'data' => [
                    'configured' => true,
                    'token_masked' => str_repeat('*', max(0, strlen($token) - 4)).substr($token, -4),
                    'status_code' => $ping['status_code'] ?? 0,
                ],
            ];
        }

        return [
            'success' => true,
            'message' => 'HubSpot credentials validated.',
            'data' => [
                'configured' => true,
                'token_masked' => str_repeat('*', max(0, strlen($token) - 4)).substr($token, -4),
                'account' => Arr::get($ping, 'data.portalId'),
            ],
        ];
    }

    private function success(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];
    }

    private function resolvePayloadFromContext(): array
    {
        $payload = $this->record?->payload ?? [];

        if (isset($payload['payload']) && is_array($payload['payload'])) {
            return $payload['payload'];
        }

        return is_array($payload) ? $payload : [];
    }

    private function resolveObjectType(string $default): string
    {
        $metaObjectType = $this->event?->meta['object_type'] ?? null;
        if (is_string($metaObjectType) && trim($metaObjectType) !== '') {
            return trim($metaObjectType);
        }

        return $default;
    }

    /**
     * @return array<int, mixed>
     */
    private function normalizeQuoteWritebackPayloads(array $payload): array
    {
        if ($payload === []) {
            return [];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        foreach (['quotes', 'items', 'output_payload', 'data'] as $key) {
            $items = Arr::get($payload, $key);
            if (is_array($items) && array_is_list($items)) {
                return $items;
            }
        }

        return [$payload];
    }

    /**
     * @return list<string>
     */
    private function archivedQuoteProperties(): array
    {
        $configured = config('hubspot.archived_quotes.properties');
        $defaults = [
            'hs_object_id',
            'hs_quote_number',
            'hs_title',
            'sync_status_odoo',
            'last_sync_odoo',
            'last_error_odoo',
            'odoo_id',
        ];

        return is_array($configured)
            ? array_values(array_unique(array_filter([...$configured, ...$defaults], 'is_string')))
            : $defaults;
    }

    /**
     * @return list<string>
     */
    private function archivedQuoteSyncedStatuses(): array
    {
        $statuses = config('hubspot.archived_quotes.synced_status_values', ['success']);
        if (! is_array($statuses) || $statuses === []) {
            $statuses = ['success'];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $status): string => is_scalar($status) ? strtolower(trim((string) $status)) : '',
            $statuses
        ))));
    }

    private function firstScalar(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate) || trim((string) $candidate) === '') {
                continue;
            }

            return trim((string) $candidate);
        }

        return null;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function findInvoiceObjectMatch(string $objectType, string $idProperty, string $invoiceId, array $properties): array
    {
        $searches = [[$idProperty, $invoiceId]];

        foreach (Arr::wrap($this->event?->meta['invoice_match_fallback_properties'] ?? []) as $fallbackProperty) {
            if (! is_string($fallbackProperty) || trim($fallbackProperty) === '') {
                continue;
            }

            $fallbackProperty = trim($fallbackProperty);
            $fallbackValue = $properties[$fallbackProperty] ?? null;
            if (is_scalar($fallbackValue) && trim((string) $fallbackValue) !== '') {
                $searches[] = [$fallbackProperty, trim((string) $fallbackValue)];
            }
        }

        foreach ($searches as [$property, $value]) {
            $search = $this->hubspotApi->searchObjectByProperty($objectType, $property, (string) $value, array_keys($properties));
            if (! ($search['success'] ?? false)) {
                return [
                    'success' => false,
                    'match_property' => $property,
                    'match_value' => (string) $value,
                    'error' => $search['error'] ?? null,
                ];
            }

            $results = Arr::get($search, 'data.results', []);
            if (! is_array($results)) {
                $results = [];
            }

            if (count($results) > 1) {
                return [
                    'success' => true,
                    'status' => 'warning',
                    'match_property' => $property,
                    'match_value' => (string) $value,
                    'matches' => $results,
                ];
            }

            if (count($results) === 1) {
                return [
                    'success' => true,
                    'object_id' => (string) Arr::get($results, '0.id'),
                    'match_property' => $property,
                    'match_value' => (string) $value,
                ];
            }
        }

        return [
            'success' => true,
            'object_id' => null,
            'match_property' => $idProperty,
            'match_value' => $invoiceId,
        ];
    }

    private function buildInvoiceObjectProperties(array $payload, array $invoice, string $invoiceId): array
    {
        $properties = [
            'odoo_id' => $invoiceId,
            'last_sync_odoo' => now()->toISOString(),
            'sync_status_odoo' => 'success',
        ];

        $properties = $this->applyInvoiceObjectRelationshipMappings($properties, $payload);

        $configuredMap = $this->event?->meta['invoice_property_map'] ?? [];
        if (is_array($configuredMap)) {
            foreach ($configuredMap as $hubspotProperty => $sourcePath) {
                if (! is_string($hubspotProperty) || trim($hubspotProperty) === '' || ! is_string($sourcePath) || trim($sourcePath) === '') {
                    continue;
                }

                $value = Arr::get($payload, $sourcePath);
                if (is_scalar($value) || $value === null) {
                    $properties[trim($hubspotProperty)] = $value;
                }
            }
        }

        return array_filter($properties, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function applyInvoiceObjectRelationshipMappings(array $properties, array $payload): array
    {
        foreach ($this->invoiceObjectMappingEvents() as $event) {
            $event->loadMissing(['propertyRelationships.property', 'propertyRelationships.relatedProperty']);

            foreach ($event->propertyRelationships as $relationship) {
                if (! $relationship instanceof PropertyRelationship || ! $relationship->active) {
                    continue;
                }

                $targetKey = $relationship->relatedProperty?->key ?: $relationship->relatedProperty?->name;
                if (! is_string($targetKey) || trim($targetKey) === '' || $this->isInvoiceObjectTechnicalPropertyKey($targetKey)) {
                    continue;
                }

                if ($relationship->relatedProperty && (int) $relationship->relatedProperty->platform_id !== (int) $this->platform->id) {
                    continue;
                }

                $sourceKey = $relationship->mapping_key
                    ?: ($relationship->property?->key ?: $relationship->property?->name);

                if (! is_string($sourceKey) || trim($sourceKey) === '') {
                    continue;
                }

                $value = data_get($payload, $sourceKey);
                if ($value === null) {
                    $value = data_get($payload, $targetKey);
                }

                if (! is_scalar($value) && $value !== null) {
                    continue;
                }

                $properties[trim($targetKey)] = $value;
            }
        }

        return $properties;
    }

    /**
     * @return list<Event>
     */
    private function invoiceObjectMappingEvents(): array
    {
        $events = [];

        $record = $this->record;
        while ($record) {
            $record->loadMissing('event', 'parent');
            if ($record->event instanceof Event) {
                $events[] = $record->event;
            }

            $record = $record->parent;
        }

        if ($this->event instanceof Event) {
            $events[] = $this->event;
        }

        return array_values(array_unique($events, SORT_REGULAR));
    }

    private function isInvoiceObjectTechnicalPropertyKey(string $key): bool
    {
        return in_array(trim($key), [
            'id',
            'hs_object_id',
            'hubspot_object_id',
            'deal_id',
            'hubspot_deal_id',
            '_event_metadata',
        ], true);
    }

    private function resolveInvoiceDealId(array $payload): ?string
    {
        return $this->firstScalar([
            Arr::get($payload, 'deal_id'),
            Arr::get($payload, 'hubspot_deal_id'),
            Arr::get($payload, 'hs_object_id'),
            Arr::get($payload, 'saleOrder.x_studio_deal_id'),
            Arr::get($payload, 'saleOrder.x_studio_hubspot_deal_id'),
            Arr::get($payload, 'sale_order.x_studio_deal_id'),
            Arr::get($payload, 'invoice.x_studio_deal_id'),
        ]);
    }

    private function applyPlatformConfiguration(): void
    {
        $credentials = $this->platform->credentials ?? [];
        $settings = $this->platform->settings ?? [];

        $overrides = [];

        $token = $credentials['access_token'] ?? $credentials['api_token'] ?? null;
        if (is_string($token) && trim($token) !== '') {
            $overrides['hubspot.access_token'] = $token;
        }

        $baseUrl = $settings['base_url'] ?? null;
        if (is_string($baseUrl) && trim($baseUrl) !== '') {
            $overrides['hubspot.base_url'] = $baseUrl;
        }

        $timeout = $settings['timeout_seconds'] ?? null;
        if (is_numeric($timeout)) {
            $overrides['hubspot.timeout_seconds'] = (int) $timeout;
        }

        if (! empty($overrides)) {
            config($overrides);
        }
    }

    private function tryLogQuoteSyncDealNote(string $objectType, string $quoteId, array $quotePayload, array $properties, array $response): array
    {
        if (! in_array(strtolower(trim($objectType)), ['quote', 'quotes'], true)) {
            return [
                'attempted' => false,
                'reason' => 'object_type_not_quote',
            ];
        }

        $dealId = $this->resolveHubspotDealIdFromQuotePayload($quotePayload, $quoteId);
        if ($dealId === null) {
            return [
                'attempted' => false,
                'reason' => 'deal_id_missing',
                'quote_id' => $quoteId,
            ];
        }

        $noteResponse = $this->hubspotApi->addNoteToObject(
            'deals',
            $dealId,
            $this->buildQuoteSyncDealNote($quoteId, $quotePayload, $properties, $response),
            [
                'event_id' => $this->event?->id,
                'record_id' => $this->record?->id,
                'quote_id' => $quotePayload['quote_id'] ?? $quotePayload['hubspot_quote_id'] ?? $quoteId,
                'operation' => $quotePayload['operation'] ?? null,
            ]
        );

        return [
            'attempted' => true,
            'success' => (bool) ($noteResponse['success'] ?? false),
            'deal_id' => $dealId,
            'quote_id' => $quoteId,
            'note_id' => $noteResponse['data']['id'] ?? null,
            'status_code' => $noteResponse['status_code'] ?? null,
            'error' => $noteResponse['error'] ?? null,
        ];
    }

    private function tryLogArchivedQuoteCancellationDealNote(string $objectType, string $quoteId, array $quotePayload, array $properties, array $response): array
    {
        if (! in_array(strtolower(trim($objectType)), ['quote', 'quotes'], true)) {
            return [
                'attempted' => false,
                'reason' => 'object_type_not_quote',
            ];
        }

        $dealId = $this->resolveHubspotDealIdFromQuotePayload($quotePayload, $quoteId);
        if ($dealId === null) {
            return [
                'attempted' => false,
                'reason' => 'deal_id_missing',
                'quote_id' => $quoteId,
            ];
        }

        $noteResponse = $this->hubspotApi->addNoteToObject(
            'deals',
            $dealId,
            $this->buildArchivedQuoteCancellationDealNote($quoteId, $quotePayload, $properties, $response),
            [
                'event_id' => $this->event?->id,
                'record_id' => $this->record?->id,
                'quote_id' => $quotePayload['quote_id'] ?? $quotePayload['hubspot_quote_id'] ?? $quoteId,
                'operation' => $quotePayload['operation'] ?? 'cancelled',
            ]
        );

        return [
            'attempted' => true,
            'success' => (bool) ($noteResponse['success'] ?? false),
            'deal_id' => $dealId,
            'quote_id' => $quoteId,
            'note_id' => $noteResponse['data']['id'] ?? null,
            'status_code' => $noteResponse['status_code'] ?? null,
            'error' => $noteResponse['error'] ?? null,
        ];
    }

    private function resolveHubspotDealIdFromQuotePayload(array $payload, string $quoteId): ?string
    {
        $dealId = $this->firstScalar([
            Arr::get($payload, 'deal_id'),
            Arr::get($payload, 'hubspot_deal_id'),
            Arr::get($payload, 'source_quote.deal_id'),
            Arr::get($payload, 'destination_response.data.source_quote.deal_id'),
            Arr::get($payload, 'raw.deal_id'),
            Arr::get($payload, 'raw.hubspot_deal_id'),
            Arr::get($payload, 'raw.deal.id'),
            Arr::get($payload, 'raw.associations.deals.0.id'),
            Arr::get($payload, 'raw.associations.deals.results.0.id'),
            Arr::get($payload, 'associations.deals.0.id'),
            Arr::get($payload, 'associations.deals.results.0.id'),
        ]);

        if ($dealId !== null) {
            return $dealId;
        }

        if (trim($quoteId) === '') {
            return null;
        }

        $associationResponse = $this->hubspotApi->getObjectAssociations('quotes', $quoteId, 'deals');
        if (! ($associationResponse['success'] ?? false)) {
            return null;
        }

        return $this->firstScalar([
            Arr::get($associationResponse, 'data.results.0.toObjectId'),
            Arr::get($associationResponse, 'data.results.0.id'),
        ]);
    }

    private function buildQuoteSyncDealNote(string $quoteId, array $quotePayload, array $properties, array $response): string
    {
        $success = (bool) ($response['success'] ?? false);
        $status = $properties['sync_status_odoo'] ?? ($success ? 'success' : 'error');
        $operation = $quotePayload['operation'] ?? null;
        $odooId = $properties['odoo_id']
            ?? Arr::get($quotePayload, 'destination_response.data.id')
            ?? Arr::get($quotePayload, 'destination_response.data.external_id');
        $error = $properties['last_error_odoo']
            ?? $response['message']
            ?? Arr::get($response, 'error.message')
            ?? Arr::get($response, 'error.details.message');

        $lines = [
            $success
                ? '[Integrador] Cotizacion sincronizada con Odoo'
                : '[Integrador] Error al sincronizar cotizacion con Odoo',
            'Cotizacion HubSpot: '.$quoteId,
            'Folio/Cotizacion: '.(string) ($quotePayload['quote_id'] ?? $quotePayload['hubspot_quote_id'] ?? $quoteId),
            'Estado: '.(string) $status,
            $operation ? 'Operacion: '.(string) $operation : null,
            $odooId ? 'Odoo ID: '.(string) $odooId : null,
            (! $success && is_scalar($error) && trim((string) $error) !== '')
                ? 'Error: '.mb_substr(trim((string) $error), 0, 500)
                : null,
            'Fecha: '.now()->toISOString(),
        ];

        return implode("\n", array_values(array_filter($lines)));
    }

    private function buildArchivedQuoteCancellationDealNote(string $quoteId, array $quotePayload, array $properties, array $response): string
    {
        $success = (bool) ($response['success'] ?? false);
        $status = $properties['sync_status_odoo'] ?? ($success ? 'cancelled' : 'error');
        $operation = $quotePayload['operation'] ?? 'cancelled';
        $odooId = $properties['odoo_id']
            ?? Arr::get($quotePayload, 'destination_response.data.id')
            ?? Arr::get($quotePayload, 'destination_response.data.subscription_id');
        $error = $properties['last_error_odoo']
            ?? $response['message']
            ?? Arr::get($response, 'error.message')
            ?? Arr::get($response, 'error.details.message');

        $lines = [
            $success
                ? '[Integrador] Suscripcion cancelada en Odoo'
                : '[Integrador] Error al registrar cancelacion de suscripcion Odoo',
            'Cotizacion HubSpot: '.$quoteId,
            'Folio/Cotizacion: '.(string) ($quotePayload['quote_id'] ?? $quotePayload['hubspot_quote_id'] ?? $quoteId),
            'Estado: '.(string) $status,
            'Operacion: '.(string) $operation,
            $odooId ? 'Suscripcion Odoo: '.(string) $odooId : null,
            (! $success && is_scalar($error) && trim((string) $error) !== '')
                ? 'Error: '.mb_substr(trim((string) $error), 0, 500)
                : null,
            'Fecha: '.now()->toISOString(),
        ];

        return implode("\n", array_values(array_filter($lines)));
    }

    private function tryLogContactFailureNote(string $objectType, string $objectId, string $message, array $response): array
    {
        $normalizedType = strtolower(trim($objectType));
        if (! in_array($normalizedType, ['contact', 'contacts'], true)) {
            return [
                'attempted' => false,
                'reason' => 'object_type_not_contact',
            ];
        }

        if (trim($objectId) === '') {
            return [
                'attempted' => false,
                'reason' => 'contact_id_missing',
            ];
        }

        $noteResponse = $this->hubspotApi->addNoteToObject(
            'contacts',
            $objectId,
            $this->buildOperationalContactFailureNote($message, $response),
            [
                'event_id' => $this->event?->id,
                'record_id' => $this->record?->id,
                'error_message' => $response['message'] ?? null,
            ]
        );

        return [
            'attempted' => true,
            'success' => (bool) ($noteResponse['success'] ?? false),
            'contact_id' => $objectId,
            'note_id' => $noteResponse['data']['id'] ?? null,
            'status_code' => $noteResponse['status_code'] ?? null,
            'error' => $noteResponse['error'] ?? null,
        ];
    }

    private function resolveHubspotContactIdFromPayload(array $payload): ?string
    {
        $candidates = [
            Arr::get($payload, 'hubspot_contact_id'),
            Arr::get($payload, 'hubspot_object_id'),
            Arr::get($payload, 'contact.id'),
            Arr::get($payload, 'contact.hubspot_id'),
            Arr::get($payload, 'hubspot_id'),
            Arr::get($payload, 'objectId'),
            Arr::get($payload, 'id'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveResponseMappingEventId(array $payload): ?int
    {
        $candidate = $this->event?->meta['response_mapping_event_id']
            ?? Arr::get($payload, 'destination_execution.destination_event_id')
            ?? Arr::get($payload, 'destination_execution.source_event_id')
            ?? Arr::get($payload, 'source_event_id');

        return is_numeric($candidate) ? (int) $candidate : null;
    }

    private function resolveAspelMappingEventId(array $payload): ?int
    {
        $candidate = $this->event?->meta['response_mapping_event_id']
            ?? $this->event?->meta['mapping_event_id']
            ?? Arr::get($payload, 'source_event_id');

        return is_numeric($candidate) ? (int) $candidate : null;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function buildHubspotContactPropertiesFromResponse(?int $mappingEventId, array $payload): array
    {
        if (! $mappingEventId) {
            return [];
        }

        $mappingEvent = Event::query()
            ->with(['propertyRelationships.property', 'propertyRelationships.relatedProperty'])
            ->find($mappingEventId);

        if (! $mappingEvent) {
            return [];
        }

        $properties = [];
        $responseData = Arr::get($payload, 'destination_response.data', []);
        $responseNestedData = Arr::get($responseData, 'data', []);

        $relationships = $mappingEvent->propertyRelationships
            ->filter(static fn (PropertyRelationship $relationship): bool => (bool) $relationship->active);

        foreach ($relationships as $relationship) {
            $hubspotKey = $relationship->property?->key ?: $relationship->property?->name;
            $targetKey = $relationship->relatedProperty?->key ?: $relationship->relatedProperty?->name;

            if (! is_string($hubspotKey) || trim($hubspotKey) === '' || ! is_string($targetKey) || trim($targetKey) === '') {
                continue;
            }

            $value = $this->firstMappedValue([
                Arr::get($responseData, $targetKey),
                Arr::get($responseNestedData, $targetKey),
            ]);

            if ($value === null) {
                continue;
            }

            $properties[$hubspotKey] = $this->normalizeHubspotPropertyValue(
                $this->applyRelationshipTransform($relationship, $value)
            );
        }

        return $properties;
    }

    private function firstMappedValue(array $candidates): mixed
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function buildHubspotContactPropertiesFromSource(?int $mappingEventId, array $sourceData): array
    {
        if (! $mappingEventId) {
            return [];
        }

        $mappingEvent = Event::query()
            ->with(['propertyRelationships.property', 'propertyRelationships.relatedProperty'])
            ->find($mappingEventId);

        if (! $mappingEvent) {
            return [];
        }

        $properties = [];
        $nestedData = Arr::get($sourceData, 'data', []);

        $relationships = $mappingEvent->propertyRelationships
            ->filter(static fn (PropertyRelationship $relationship): bool => (bool) $relationship->active);

        foreach ($relationships as $relationship) {
            $hubspotKey = $relationship->property?->key ?: $relationship->property?->name;
            $sourceKey = $relationship->relatedProperty?->key ?: $relationship->relatedProperty?->name;

            if (! is_string($hubspotKey) || trim($hubspotKey) === '' || ! is_string($sourceKey) || trim($sourceKey) === '') {
                continue;
            }

            $value = $this->firstMappedValue([
                Arr::get($sourceData, $sourceKey),
                Arr::get($nestedData, $sourceKey),
            ]);

            if ($value === null) {
                continue;
            }

            $properties[$hubspotKey] = $this->normalizeHubspotPropertyValue(
                $this->applyRelationshipTransform($relationship, $value)
            );
        }

        return $properties;
    }

    private function applyRelationshipTransform(PropertyRelationship $relationship, mixed $value): mixed
    {
        $meta = is_array($relationship->meta) ? $relationship->meta : [];
        $transform = $meta['transform'] ?? null;

        if (! is_string($transform) || trim($transform) === '') {
            return $value;
        }

        return match (trim($transform)) {
            'hubspot_datetime_to_millis',
            'hubspot_date_to_millis' => $this->convertDateValueToHubspotMillis($value),
            default => $value,
        };
    }

    private function convertDateValueToHubspotMillis(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }

        if (! is_scalar($value)) {
            return $value;
        }

        try {
            return Carbon::parse((string) $value)->utc()->getTimestampMs();
        } catch (\Throwable) {
            return $value;
        }
    }

    private function normalizeHubspotPropertyValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function buildOperationalContactFailureNote(string $message, array $response): string
    {
        $propertyName = $this->extractHubspotErrorPropertyName($response);
        $errorCode = $this->extractHubspotErrorCode($response);
        $category = Arr::get($response, 'error.category');

        return trim(implode("\n", array_filter([
            '[Integrador] Error de sincronizacion de contacto',
            'Operacion: '.$this->resolveOperationalFailureLabel(),
            'Evento: '.($this->event?->name ?: $this->event?->event_type_id ?: 'N/A'),
            'Motivo: '.trim($message),
            $propertyName ? 'Propiedad: '.$propertyName : null,
            $errorCode ? 'Codigo: '.$errorCode : null,
            is_string($category) && trim($category) !== '' ? 'Categoria: '.$category : null,
            $this->record?->id ? 'Record: #'.$this->record->id : null,
            'Fecha: '.now()->toISOString(),
        ])));
    }

    private function resolveOperationalFailureLabel(): string
    {
        $method = $this->event?->method_name;

        return match ($method) {
            'syncContactExecutionResponse' => 'write-back a HubSpot',
            'syncAspelContactToHubspot' => 'sincronizacion de ASPEL a HubSpot',
            'updateAspelContactInHubspot' => 'actualizacion de contacto ASPEL en HubSpot',
            'createAspelContactInHubspot' => 'creacion de contacto ASPEL en HubSpot',
            'updateObject' => 'actualizacion de contacto en HubSpot',
            'createObject' => 'creacion de contacto en HubSpot',
            default => 'sincronizacion de contacto',
        };
    }

    private function extractHubspotErrorPropertyName(array $response): ?string
    {
        $contextProperty = Arr::get($response, 'error.errors.0.context.propertyName.0');
        if (is_scalar($contextProperty) && trim((string) $contextProperty) !== '') {
            return trim((string) $contextProperty);
        }

        return null;
    }

    private function extractHubspotErrorCode(array $response): ?string
    {
        $errorCode = Arr::get($response, 'error.errors.0.code')
            ?? Arr::get($response, 'error.code');

        if (! is_scalar($errorCode) || trim((string) $errorCode) === '') {
            return null;
        }

        return trim((string) $errorCode);
    }

    /**
     * @return array<string, scalar|null>
     */
    private function buildPlatformSyncSuccessProperties(array $payload): array
    {
        $targetPlatform = $this->resolveTargetPlatformKey($payload);
        if ($targetPlatform === null) {
            return [];
        }

        $controlProperty = $this->resolvePlatformControlProperty($targetPlatform, $payload);

        return [
            $controlProperty => 'synced',
            'sync_status_'.$targetPlatform => 'success',
            'last_sync_'.$targetPlatform => now()->toISOString(),
            'last_error_'.$targetPlatform => '',
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function buildAspelInboundAuditProperties(array $payload, array $sourceData): array
    {
        $properties = [
            'last_sync_aspel' => now()->toISOString(),
            'sync_status_aspel' => 'success',
            'last_error_aspel' => '',
        ];

        $clave = $this->resolveScalarPayloadValue([
            Arr::get($payload, 'clave'),
            Arr::get($sourceData, 'clave'),
            Arr::get($sourceData, 'CLAVE'),
        ]);
        if ($clave !== null) {
            $properties['clave'] = $clave;
        }

        $versionSinc = $this->resolveScalarPayloadValue([
            Arr::get($payload, 'versionSinc'),
            Arr::get($sourceData, 'versionSinc'),
            Arr::get($sourceData, 'version_sinc'),
            Arr::get($sourceData, 'VERSION_SINC'),
        ]);
        $versionPropertyKey = $this->resolveHubspotVersionSincPropertyKey();
        if ($versionSinc !== null && $versionPropertyKey !== null) {
            $properties[$versionPropertyKey] = $versionSinc;
        }

        return $properties;
    }

    private function resolveTargetPlatformKey(array $payload): ?string
    {
        $candidates = [
            $this->event?->meta['target_platform'] ?? null,
            Arr::get($payload, 'target_platform'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            return strtolower(trim($candidate));
        }

        return null;
    }

    private function resolvePlatformControlProperty(string $targetPlatform, array $payload): string
    {
        $candidate = $this->event?->meta['control_property']
            ?? Arr::get($payload, 'control_property');

        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }

        return 'sync_to_'.$targetPlatform;
    }

    private function resolveHubspotVersionSincPropertyKey(): ?string
    {
        $configured = $this->event?->meta['version_sinc_property'] ?? null;
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $property = Property::query()
            ->where('platform_id', $this->platform->id)
            ->where(function ($query): void {
                $query->where('key', 'version_sinc_aspel')
                    ->orWhere('name', 'version_sinc_aspel');
            })
            ->first();

        if (! $property) {
            return null;
        }

        return $property->key ?: $property->name;
    }

    private function resolveScalarPayloadValue(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array{0:?int,1:array,2:array{success:bool,properties?:array,message?:string,data?:array},3:?array}
     */
    private function prepareAspelHubspotSyncContext(array $payload): array
    {
        $mappingEventId = $this->resolveAspelMappingEventId($payload);
        $sourceData = Arr::get($payload, 'aspel_detail', []);

        if (! is_array($sourceData) || $sourceData === []) {
            return [
                $mappingEventId,
                [],
                [
                    'success' => false,
                    'message' => 'Missing ASPEL contact detail payload for HubSpot sync.',
                    'data' => [
                        'mapping_event_id' => $mappingEventId,
                        'received_keys' => array_keys($payload),
                    ],
                ],
                [
                    'success' => false,
                    'message' => 'Missing ASPEL contact detail payload for HubSpot sync.',
                ],
            ];
        }

        $properties = array_merge(
            $this->buildHubspotContactPropertiesFromSource($mappingEventId, $sourceData),
            $this->buildAspelInboundAuditProperties($payload, $sourceData)
        );

        if ($properties === []) {
            return [
                $mappingEventId,
                $sourceData,
                [
                    'success' => false,
                    'message' => 'No mapped HubSpot properties found for ASPEL contact sync.',
                    'data' => [
                        'mapping_event_id' => $mappingEventId,
                        'source_keys' => array_keys($sourceData),
                    ],
                ],
                [
                    'success' => false,
                    'message' => 'No mapped HubSpot properties found for ASPEL contact sync.',
                ],
            ];
        }

        return [
            $mappingEventId,
            $sourceData,
            [
                'success' => true,
                'properties' => $properties,
            ],
            null,
        ];
    }

    /**
     * @return array{0:?int,1:array,2:array{success:bool,properties?:array,message?:string,data?:array},3:?array}
     */
    private function prepareAspelHubspotProductSyncContext(array $payload): array
    {
        $mappingEventId = $this->resolveAspelMappingEventId($payload);
        $sourceData = Arr::get($payload, 'aspel_detail', []);

        if (! is_array($sourceData) || $sourceData === []) {
            return [
                $mappingEventId,
                [],
                [
                    'success' => false,
                    'message' => 'Missing ASPEL product detail payload for HubSpot sync.',
                    'data' => [
                        'mapping_event_id' => $mappingEventId,
                        'received_keys' => array_keys($payload),
                    ],
                ],
                [
                    'success' => false,
                    'message' => 'Missing ASPEL product detail payload for HubSpot sync.',
                ],
            ];
        }

        $properties = $this->buildHubspotContactPropertiesFromSource($mappingEventId, $sourceData);

        if ($properties === []) {
            return [
                $mappingEventId,
                $sourceData,
                [
                    'success' => false,
                    'message' => 'No mapped HubSpot properties found for ASPEL product sync.',
                    'data' => [
                        'mapping_event_id' => $mappingEventId,
                        'source_keys' => array_keys($sourceData),
                    ],
                ],
                [
                    'success' => false,
                    'message' => 'No mapped HubSpot properties found for ASPEL product sync.',
                ],
            ];
        }

        return [
            $mappingEventId,
            $sourceData,
            [
                'success' => true,
                'properties' => $properties,
            ],
            null,
        ];
    }

    private function findHubspotContactForAspelPayload(array $payload, array $sourceData): array
    {
        $strategies = [
            'clave' => $this->resolveScalarPayloadValue([
                Arr::get($payload, 'clave'),
                Arr::get($sourceData, 'clave'),
                Arr::get($sourceData, 'CLAVE'),
            ]),
            'rfc' => $this->resolveScalarPayloadValue([
                Arr::get($payload, 'rfc'),
                Arr::get($sourceData, 'rfc'),
            ]),
            'phone' => $this->resolveScalarPayloadValue([
                Arr::get($payload, 'phone'),
                Arr::get($sourceData, 'phone'),
                Arr::get($sourceData, 'telefono'),
            ]),
            'email' => $this->resolveScalarPayloadValue([
                Arr::get($payload, 'email'),
                Arr::get($sourceData, 'email'),
                Arr::get($sourceData, 'emailEnvio'),
            ]),
        ];

        foreach ($strategies as $propertyName => $value) {
            if ($value === null) {
                continue;
            }

            $response = $this->hubspotApi->searchObjectByProperty('contacts', $propertyName, $value, [
                'email',
                'phone',
                'rfc',
                'clave',
            ]);

            if (! ($response['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Failed to search HubSpot contact for ASPEL change.',
                    'match_property' => $propertyName,
                    'match_value' => $value,
                    'data' => [
                        'match_property' => $propertyName,
                        'match_value' => $value,
                        'error' => $response['error'] ?? null,
                    ],
                ];
            }

            $results = Arr::get($response, 'data.results', []);
            if (! is_array($results)) {
                $results = [];
            }

            if (count($results) > 1) {
                return [
                    'success' => true,
                    'found' => false,
                    'multiple' => true,
                    'match_property' => $propertyName,
                    'match_value' => $value,
                    'matches' => array_map(
                        static fn (array $result): array => [
                            'id' => $result['id'] ?? null,
                            'properties' => $result['properties'] ?? [],
                        ],
                        $results
                    ),
                ];
            }

            if (count($results) === 1) {
                return [
                    'success' => true,
                    'found' => true,
                    'contact_id' => (string) ($results[0]['id'] ?? ''),
                    'matched_by' => $propertyName,
                ];
            }
        }

        return [
            'success' => true,
            'found' => false,
            'matched_by' => null,
        ];
    }

    private function findHubspotProductForAspelPayload(array $payload, array $sourceData): array
    {
        $clave = $this->resolveScalarPayloadValue([
            Arr::get($payload, 'clave'),
            Arr::get($sourceData, 'clave'),
            Arr::get($sourceData, 'CLAVE'),
        ]);

        if ($clave === null) {
            return [
                'success' => true,
                'found' => false,
                'matched_by' => null,
            ];
        }

        $response = $this->hubspotApi->searchObjectByProperty('products', 'clave', $clave, [
            'clave',
            'name',
        ]);

        if (! ($response['success'] ?? false)) {
            return [
                'success' => false,
                'message' => 'Failed to search HubSpot product for ASPEL change.',
                'match_property' => 'clave',
                'match_value' => $clave,
                'data' => [
                    'match_property' => 'clave',
                    'match_value' => $clave,
                    'error' => $response['error'] ?? null,
                ],
            ];
        }

        $results = Arr::get($response, 'data.results', []);
        if (! is_array($results)) {
            $results = [];
        }

        if (count($results) > 1) {
            return [
                'success' => true,
                'found' => false,
                'multiple' => true,
                'match_property' => 'clave',
                'match_value' => $clave,
                'matches' => array_map(
                    static fn (array $result): array => [
                        'id' => $result['id'] ?? null,
                        'properties' => $result['properties'] ?? [],
                    ],
                    $results
                ),
            ];
        }

        if (count($results) === 1) {
            return [
                'success' => true,
                'found' => true,
                'product_id' => (string) ($results[0]['id'] ?? ''),
                'matched_by' => 'clave',
            ];
        }

        return [
            'success' => true,
            'found' => false,
            'matched_by' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function extractDestinationResponseKeys(array $payload): array
    {
        $responseData = Arr::get($payload, 'destination_response.data', []);
        if (! is_array($responseData)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $key): string => (string) $key,
            array_keys($responseData)
        ));
    }

    private function buildPropertyChangePayload(string $subscriptionType, array $payload): array
    {
        $objectId = $payload['objectId'] ?? ($payload['object_id'] ?? null);
        $propertyName = $payload['propertyName'] ?? ($payload['property_name'] ?? null);
        $propertyValue = $payload['propertyValue'] ?? ($payload['property_value'] ?? null);

        return array_merge($payload, [
            'subscription_type' => $subscriptionType,
            'object_id' => $objectId,
            'objectId' => $objectId,
            'property_name' => $propertyName,
            'propertyName' => $propertyName,
            'property_value' => $propertyValue,
            'propertyValue' => $propertyValue,
        ]);
    }
}

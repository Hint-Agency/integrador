<?php

namespace App\Services\Hubspot;

use App\Models\Event;
use App\Models\PropertyRelationship;
use App\Services\RateLimitService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class HubspotApiServiceRefactored
{
    public function __construct(
        protected RateLimitService $rateLimitService
    ) {}

    public function ping(): array
    {
        return $this->request('GET', '/integrations/v1/me');
    }

    public function searchSignedQuotes(?Event $event = null): array
    {
        $path = (string) config('hubspot.signed_quotes.search_path', '/crm/v3/objects/quotes/search');
        $filters = $this->buildSignedQuoteFilters();
        $properties = $this->signedQuoteProperties($event);

        $results = [];
        $after = null;
        $page = 0;

        do {
            $body = [
                'filterGroups' => $filters,
                'properties' => array_values(array_unique(array_filter($properties, 'is_string'))),
                'limit' => 100,
            ];

            if ($after !== null) {
                $body['after'] = $after;
            }

            $response = $this->request('POST', $path, $body);
            if (! ($response['success'] ?? false)) {
                return $response + [
                    'data' => [
                        'results' => $results,
                        'page' => $page + 1,
                    ],
                ];
            }

            $page++;
            $pageResults = Arr::get($response, 'data.results', []);
            if (is_array($pageResults)) {
                $results = array_merge($results, array_map(
                    fn (array $quote): array => $this->hydrateSignedQuoteAssociations($quote, $event),
                    array_filter($pageResults, 'is_array')
                ));
            }

            $after = Arr::get($response, 'data.paging.next.after');
        } while (is_scalar($after) && trim((string) $after) !== '');

        return [
            'success' => true,
            'status_code' => 200,
            'data' => [
                'results' => $results,
                'total' => count($results),
                'pages' => $page,
            ],
        ];
    }

    private function hydrateSignedQuoteAssociations(array $quote, ?Event $event): array
    {
        if (! (bool) config('hubspot.signed_quotes.hydrate_associations', true)) {
            return $quote;
        }

        $quoteId = $this->firstScalar([
            Arr::get($quote, 'id'),
            Arr::get($quote, 'properties.hs_object_id'),
        ]);

        if ($quoteId === null) {
            return $quote;
        }

        $associations = [
            'companies' => $this->fetchAssociatedObjects('quotes', $quoteId, 'companies', $event),
            'contacts' => $this->fetchAssociatedObjects('quotes', $quoteId, 'contacts', $event),
            'deals' => $this->fetchAssociatedObjects('quotes', $quoteId, 'deals', $event),
            'line_items' => $this->fetchAssociatedObjects('quotes', $quoteId, 'line_items', $event),
        ];

        $dealId = $this->firstScalar([
            Arr::get($associations, 'deals.0.id'),
            Arr::get($associations, 'deals.0.toObjectId'),
        ]);

        if ($dealId !== null) {
            foreach (['companies', 'contacts', 'line_items'] as $type) {
                if ($associations[$type] !== []) {
                    continue;
                }

                $associations[$type] = $this->fetchAssociatedObjects('deals', $dealId, $type, $event);
            }
        }

        return $quote + [
            'deal_id' => $dealId,
            'associations' => $associations,
            'entities' => [
                'company' => $this->buildAssociatedEntity(Arr::get($associations, 'companies.0', [])),
                'contact' => $this->buildAssociatedEntity(Arr::get($associations, 'contacts.0', [])),
                'products' => array_map(
                    fn (array $lineItem): array => $this->buildAssociatedEntity($lineItem),
                    array_filter(Arr::get($associations, 'line_items', []), 'is_array')
                ),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAssociatedObjects(string $fromObjectType, string $fromObjectId, string $toObjectType, ?Event $event): array
    {
        $associationResponse = $this->getObjectAssociations($fromObjectType, $fromObjectId, $toObjectType);
        if (! ($associationResponse['success'] ?? false)) {
            return [];
        }

        $results = Arr::get($associationResponse, 'data.results', []);
        if (! is_array($results)) {
            return [];
        }

        $objects = [];
        foreach ($results as $association) {
            if (! is_array($association)) {
                continue;
            }

            $objectId = $this->firstScalar([
                Arr::get($association, 'toObjectId'),
                Arr::get($association, 'id'),
            ]);

            if ($objectId === null) {
                continue;
            }

            $object = [
                'id' => $objectId,
                'association' => $association,
                'role' => $this->resolveAssociationRole($association),
            ];

            $detailResponse = $this->getObject($toObjectType, $objectId, $this->associatedObjectProperties($toObjectType, $event));
            if ($detailResponse['success'] ?? false) {
                $data = Arr::get($detailResponse, 'data', []);
                if (is_array($data)) {
                    $object = array_replace_recursive($data, $object);
                }
            }

            if ($this->normalizeObjectType($toObjectType) === 'deals') {
                $object = $this->enrichDealOwner($object);
            }

            $objects[$objectId] = $object;
        }

        return array_values($objects);
    }

    private function buildAssociatedEntity(array $object): array
    {
        if ($object === []) {
            return [];
        }

        $properties = Arr::get($object, 'properties', []);
        if (! is_array($properties)) {
            $properties = [];
        }

        return [
            'id' => Arr::get($object, 'id'),
            'hubspot_id' => Arr::get($object, 'id'),
            'odoo_id' => Arr::get($properties, 'odoo_id'),
            'fields' => array_filter($properties, static fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    /**
     * @return list<string>
     */
    private function signedQuoteProperties(?Event $event): array
    {
        return $this->mergePropertyLists(
            $this->configuredPropertyList('hubspot.signed_quotes.properties', ['hs_title', 'hs_status', 'hs_quote_number']),
            $this->mappedSourceProperties($event, ['quote'])
        );
    }

    private function associatedObjectProperties(string $objectType, ?Event $event): array
    {
        $normalized = $this->normalizeObjectType($objectType);
        $prefixes = match ($normalized) {
            'companies' => ['company'],
            'contacts' => ['contact'],
            'deals' => ['deal'],
            'line_items' => ['product', 'line_item', 'line_items'],
            default => [],
        };
        $defaults = $normalized === 'deals'
            ? ['hs_object_id', 'odoo_id', 'hubspot_owner_id']
            : ['hs_object_id', 'odoo_id'];

        return $this->mergePropertyLists(
            $this->configuredPropertyList('hubspot.signed_quotes.association_fallback_properties.'.$normalized, $defaults),
            $defaults,
            $this->mappedSourceProperties($event, $prefixes)
        );
    }

    private function enrichDealOwner(array $deal): array
    {
        $ownerId = $this->firstScalar([
            Arr::get($deal, 'properties.hubspot_owner_id'),
            Arr::get($deal, 'hubspot_owner_id'),
        ]);

        if ($ownerId === null) {
            return $deal;
        }

        $ownerResponse = $this->getOwner($ownerId);
        if (! ($ownerResponse['success'] ?? false)) {
            $deal['owner_lookup'] = [
                'success' => false,
                'owner_id' => $ownerId,
                'status_code' => $ownerResponse['status_code'] ?? null,
                'error' => $ownerResponse['error'] ?? null,
            ];

            return $deal;
        }

        $owner = Arr::get($ownerResponse, 'data', []);
        if (! is_array($owner) || $owner === []) {
            return $deal;
        }

        $firstName = $this->firstScalar([Arr::get($owner, 'firstName')]);
        $lastName = $this->firstScalar([Arr::get($owner, 'lastName')]);
        $fullName = trim(implode(' ', array_filter([$firstName, $lastName])));

        $deal['owner'] = [
            'id' => Arr::get($owner, 'id', $ownerId),
            'user_id' => Arr::get($owner, 'userId'),
            'email' => Arr::get($owner, 'email'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName !== '' ? $fullName : null,
            'teams' => Arr::get($owner, 'teams', []),
            'archived' => Arr::get($owner, 'archived'),
        ];

        return $deal;
    }

    /**
     * @param  list<string>  $prefixes
     * @return list<string>
     */
    private function mappedSourceProperties(?Event $event, array $prefixes): array
    {
        if (! $event) {
            return [];
        }

        $event->loadMissing('propertyRelationships.property');
        $properties = [];

        foreach ($event->propertyRelationships as $relationship) {
            if (! $relationship instanceof PropertyRelationship || ! $relationship->active) {
                continue;
            }

            $sourceKey = $relationship->mapping_key
                ?: ($relationship->property?->key ?: $relationship->property?->name);

            $property = $this->extractMappedHubspotProperty($sourceKey, $prefixes);
            if ($property !== null) {
                $properties[] = $property;
            }
        }

        return array_values(array_unique($properties));
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function extractMappedHubspotProperty(mixed $sourceKey, array $prefixes): ?string
    {
        if (! is_scalar($sourceKey)) {
            return null;
        }

        $sourceKey = trim((string) $sourceKey);
        if ($sourceKey === '') {
            return null;
        }

        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix, '.');
            if ($prefix !== '' && str_starts_with($sourceKey, $prefix.'.')) {
                $property = trim(substr($sourceKey, strlen($prefix) + 1));

                return $property !== '' ? $property : null;
            }
        }

        if (in_array('quote', $prefixes, true) && ! str_contains($sourceKey, '.')) {
            return $sourceKey;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function configuredPropertyList(string $key, array $default = []): array
    {
        $properties = config($key, $default);

        return is_array($properties)
            ? array_values(array_unique(array_filter($properties, 'is_string')))
            : $default;
    }

    /**
     * @param  list<string>  ...$lists
     * @return list<string>
     */
    private function mergePropertyLists(array ...$lists): array
    {
        return array_values(array_unique(array_filter(array_merge(...$lists), 'is_string')));
    }

    private function resolveAssociationRole(array $association): ?string
    {
        $label = Arr::get($association, 'associationTypes.0.label')
            ?? Arr::get($association, 'types.0.label')
            ?? Arr::get($association, 'label');

        return is_scalar($label) && trim((string) $label) !== '' ? trim((string) $label) : null;
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

    private function buildSignedQuoteFilters(): array
    {
        $signStatusProperty = (string) config('hubspot.signed_quotes.sign_status_property', 'hs_sign_status');
        $signStatusValues = config('hubspot.signed_quotes.sign_status_values', ['SIGNED', 'MANUALLY_SIGNED']);
        if (! is_array($signStatusValues) || $signStatusValues === []) {
            $signStatusValues = ['SIGNED', 'MANUALLY_SIGNED'];
        }

        $lookbackDays = max(1, (int) config('hubspot.signed_quotes.lookback_days', 2));
        $now = now();
        $baseFilters = [
            [
                'propertyName' => $signStatusProperty,
                'operator' => 'IN',
                'values' => array_values(array_unique(array_filter($signStatusValues, 'is_string'))),
            ],
            [
                'propertyName' => (string) config('hubspot.signed_quotes.modified_property', 'hs_lastmodifieddate'),
                'operator' => 'BETWEEN',
                'value' => (string) $now->copy()->subDays($lookbackDays)->getTimestampMs(),
                'highValue' => (string) $now->getTimestampMs(),
            ],
            [
                'propertyName' => (string) config('hubspot.signed_quotes.archived_property', 'hs_archived'),
                'operator' => 'NOT_HAS_PROPERTY',
            ],
        ];

        $syncStatusProperty = (string) config('hubspot.signed_quotes.sync_status_property', 'sync_status_odoo');
        $syncedStatuses = config('hubspot.signed_quotes.synced_status_values', ['success', 'already_exists']);
        if (! is_array($syncedStatuses) || $syncedStatuses === []) {
            $syncedStatuses = ['success', 'already_exists'];
        }

        return [
            [
                'filters' => [
                    ...$baseFilters,
                    [
                        'propertyName' => $syncStatusProperty,
                        'operator' => 'NOT_HAS_PROPERTY',
                    ],
                ],
            ],
            [
                'filters' => [
                    ...$baseFilters,
                    [
                        'propertyName' => $syncStatusProperty,
                        'operator' => 'NOT_IN',
                        'values' => array_values(array_unique(array_filter($syncedStatuses, 'is_string'))),
                    ],
                ],
            ],
        ];
    }

    public function createProduct(array $payload): array
    {
        return $this->request('POST', '/crm/v3/objects/products', [
            'properties' => $payload,
        ]);
    }

    public function updateProduct(string $productId, array $payload): array
    {
        return $this->request('PATCH', '/crm/v3/objects/products/'.$productId, [
            'properties' => $payload,
        ]);
    }

    public function searchObjectByProperty(string $objectType, string $propertyName, mixed $value, array $properties = []): array
    {
        $normalizedObjectType = $this->normalizeObjectType($objectType);
        $normalizedPropertyName = trim($propertyName);

        if ($normalizedPropertyName === '') {
            return $this->errorResponse(0, 'HubSpot property name is required for search.');
        }

        if (! is_scalar($value) || trim((string) $value) === '') {
            return $this->errorResponse(0, 'HubSpot property value is required for search.');
        }

        $requestedProperties = array_values(array_unique(array_filter(array_map(
            static fn (mixed $property): string => is_scalar($property) ? trim((string) $property) : '',
            $properties
        ))));

        if (! in_array($normalizedPropertyName, $requestedProperties, true)) {
            $requestedProperties[] = $normalizedPropertyName;
        }

        return $this->request('POST', '/crm/v3/objects/'.$normalizedObjectType.'/search', [
            'filterGroups' => [[
                'filters' => [[
                    'propertyName' => $normalizedPropertyName,
                    'operator' => 'EQ',
                    'value' => trim((string) $value),
                ]],
            ]],
            'properties' => $requestedProperties,
            'limit' => 2,
        ]);
    }

    public function createInvoice(array $payload): array
    {
        return $this->request('POST', '/crm/v3/objects/invoices', [
            'properties' => $payload,
        ]);
    }

    public function createObject(string $objectType, array $payload): array
    {
        return $this->request('POST', '/crm/v3/objects/'.$this->normalizeObjectType($objectType), [
            'properties' => $payload,
        ]);
    }

    public function getObject(string $objectType, string $objectId, array $properties = []): array
    {
        $query = [];
        $requested = array_values(array_filter(array_map(
            static fn (mixed $property): string => is_scalar($property) ? trim((string) $property) : '',
            $properties
        )));

        if (! empty($requested)) {
            $query['properties'] = implode(',', array_unique($requested));
        }

        return $this->request('GET', '/crm/v3/objects/'.$this->normalizeObjectType($objectType).'/'.$objectId, [], $query);
    }

    public function getOwner(string $ownerId, string $idProperty = 'id'): array
    {
        if (trim($ownerId) === '') {
            return $this->errorResponse(0, 'HubSpot owner id is required.');
        }

        return $this->request(
            'GET',
            rtrim((string) config('hubspot.owners.path', '/crm/v3/owners'), '/').'/'.rawurlencode($ownerId),
            [],
            ['idProperty' => $idProperty]
        );
    }

    public function updateObject(string $objectType, string $objectId, array $payload): array
    {
        return $this->request('PATCH', '/crm/v3/objects/'.$this->normalizeObjectType($objectType).'/'.$objectId, [
            'properties' => $payload,
        ]);
    }

    public function getObjectAssociations(string $fromObjectType, string $fromObjectId, string $toObjectType): array
    {
        if (trim($fromObjectId) === '') {
            return $this->errorResponse(0, 'HubSpot object id is required to fetch associations.');
        }

        return $this->request(
            'GET',
            sprintf(
                '/crm/v4/objects/%s/%s/associations/%s',
                $this->normalizeObjectType($fromObjectType),
                $fromObjectId,
                $this->normalizeObjectType($toObjectType)
            )
        );
    }

    public function associateObjects(
        string $fromObjectType,
        string $fromObjectId,
        string $toObjectType,
        string $toObjectId,
        ?int $associationTypeId = null
    ): array {
        if ($associationTypeId === null) {
            return $this->request(
                'PUT',
                sprintf(
                    '/crm/v4/objects/%s/%s/associations/default/%s/%s',
                    $this->normalizeObjectType($fromObjectType),
                    $fromObjectId,
                    $this->normalizeObjectType($toObjectType),
                    $toObjectId
                )
            );
        }

        return $this->request(
            'PUT',
            sprintf(
                '/crm/v3/objects/%s/%s/associations/%s/%s/%d',
                $this->normalizeObjectType($fromObjectType),
                $fromObjectId,
                $this->normalizeObjectType($toObjectType),
                $toObjectId,
                $associationTypeId
            )
        );
    }

    public function addNoteToObject(string $objectType, string $objectId, string $message, array $context = []): array
    {
        $normalizedType = $this->normalizeObjectType($objectType);
        $associationType = $this->resolveNoteAssociationType($normalizedType);
        $associationObjectType = $this->resolveAssociationObjectType($normalizedType);
        $recordToNoteAssociationType = $this->resolveRecordToNoteAssociationType($normalizedType);

        if ($objectId === '') {
            return $this->errorResponse(0, 'HubSpot object id is required to create note.');
        }

        if (! $associationType || ! $associationObjectType || ! $recordToNoteAssociationType) {
            return $this->errorResponse(0, 'HubSpot association type is not configured for note creation.', [
                'object_type' => $normalizedType,
            ]);
        }

        $contextLines = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $contextLines[] = sprintf('%s: %s', (string) $key, (string) $value);
            }
        }

        $noteBody = trim(implode("\n", array_filter([
            trim($message),
            empty($contextLines) ? null : 'Context: '.implode(' | ', $contextLines),
        ])));

        $createResponse = $this->request('POST', '/crm/v3/objects/notes', [
            'properties' => [
                'hs_timestamp' => now()->toISOString(),
                'hs_note_body' => $noteBody,
            ],
            'associations' => [[
                'to' => [
                    'id' => (string) $objectId,
                ],
                'types' => [[
                    'associationCategory' => 'HUBSPOT_DEFINED',
                    'associationTypeId' => $associationType,
                ]],
            ]],
        ]);

        if (! ($createResponse['success'] ?? false)) {
            return $createResponse;
        }

        $noteId = (string) Arr::get($createResponse, 'data.id', '');
        if ($noteId === '') {
            return $createResponse;
        }

        $associationResponse = $this->request(
            'PUT',
            sprintf(
                '/crm/v3/objects/notes/%s/associations/%s/%s/%d',
                $noteId,
                $associationObjectType,
                (string) $objectId,
                $associationType
            )
        );

        if (! ($associationResponse['success'] ?? false)) {
            $createResponse['association'] = [
                'success' => false,
                'status_code' => $associationResponse['status_code'] ?? null,
                'error' => $associationResponse['error'] ?? null,
                'object_type' => $associationObjectType,
                'association_type_id' => $associationType,
            ];

            return $createResponse;
        }

        $timelineAssociationResponse = $this->request(
            'PUT',
            sprintf(
                '/crm/v3/objects/%s/%s/associations/notes/%s/%d',
                $associationObjectType,
                (string) $objectId,
                $noteId,
                $recordToNoteAssociationType
            )
        );

        if (! ($timelineAssociationResponse['success'] ?? false)) {
            $createResponse['timeline_association'] = [
                'success' => false,
                'status_code' => $timelineAssociationResponse['status_code'] ?? null,
                'error' => $timelineAssociationResponse['error'] ?? null,
                'object_type' => $associationObjectType,
                'association_type_id' => $recordToNoteAssociationType,
            ];

            return $createResponse;
        }

        $createResponse['association'] = [
            'success' => true,
            'status_code' => $associationResponse['status_code'] ?? null,
            'object_type' => $associationObjectType,
            'association_type_id' => $associationType,
        ];
        $createResponse['timeline_association'] = [
            'success' => true,
            'status_code' => $timelineAssociationResponse['status_code'] ?? null,
            'object_type' => $associationObjectType,
            'association_type_id' => $recordToNoteAssociationType,
        ];

        return $createResponse;
    }

    public function request(string $method, string $path, array $payload = [], array $query = []): array
    {
        $token = config('hubspot.access_token');
        if (! $token) {
            return $this->errorResponse(0, 'HubSpot access token is not configured.');
        }

        $baseUrl = rtrim((string) config('hubspot.base_url', 'https://api.hubapi.com'), '/');
        $url = $baseUrl.'/'.ltrim($path, '/');

        $this->rateLimitService->throttle('hubspot', $path);

        $request = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('hubspot.timeout_seconds', 30));

        /** @var Response $response */
        $response = $request->send(strtoupper($method), $url, [
            'query' => $query,
            'json' => $payload,
        ]);

        if ($response->failed()) {
            return $this->errorResponse(
                $response->status(),
                'HubSpot request failed.',
                $response->json() ?? ['raw' => $response->body()]
            );
        }

        return [
            'success' => true,
            'status_code' => $response->status(),
            'data' => $response->json() ?? [],
        ];
    }

    private function errorResponse(int $status, string $message, array $details = []): array
    {
        return [
            'success' => false,
            'status_code' => $status,
            'message' => $message,
            'error' => $details,
        ];
    }

    private function normalizeObjectType(string $objectType): string
    {
        return match (strtolower(trim($objectType))) {
            'contact', 'contacts' => 'contacts',
            'company', 'companies' => 'companies',
            'deal', 'deals' => 'deals',
            'quote', 'quotes' => 'quotes',
            default => strtolower(trim($objectType)),
        };
    }

    private function resolveNoteAssociationType(string $objectType): ?int
    {
        $configured = Arr::get(config('hubspot.note_association_type_ids', []), $objectType);
        if (is_numeric($configured)) {
            return (int) $configured;
        }

        return match ($objectType) {
            'contacts' => 202,
            'companies' => 190,
            'deals' => 214,
            default => null,
        };
    }

    private function resolveAssociationObjectType(string $objectType): ?string
    {
        return match ($objectType) {
            'contacts' => 'contact',
            'companies' => 'company',
            'deals' => 'deal',
            default => null,
        };
    }

    private function resolveRecordToNoteAssociationType(string $objectType): ?int
    {
        return match ($objectType) {
            'contacts' => 201,
            'companies' => 190,
            'deals' => 213,
            default => null,
        };
    }
}

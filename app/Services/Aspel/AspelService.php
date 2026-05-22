<?php

namespace App\Services\Aspel;

use App\Models\Config;
use App\Models\Event;
use App\Models\EventIdempotencyKey;
use App\Models\Platform;
use App\Models\Record;
use App\Services\EventLoggingService;
use App\Services\EventProcessingService;
use App\Services\Generic\GenericHttpAdapter;
use App\Services\Generic\AuthStrategyResolver;
use App\Services\Generic\GenericPlatformService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AspelService extends GenericPlatformService
{
    private const DEFAULT_CHANGES_TAKE = 200;
    private const DEFAULT_INITIAL_LOOKBACK_HOURS = 24;
    private const CHANGE_IDEMPOTENCY_TTL_HOURS = 24 * 30;
    private const CHANGE_IDEMPOTENCY_STALE_MINUTES = 15;
    private const DETAIL_NOT_FOUND_RETRY_BACKOFF_SECONDS = [1, 3];

    /**
     * @var list<string>
     */
    private const TECHNICAL_KEYS = [
        '_event_metadata',
        'destination_execution',
        'destination_response',
        'hubspotObjectId',
        'hubspot_contact_id',
        'hubspot_id',
        'hubspot_object',
        'hubspot_object_id',
        'hubspot_object_type',
        'hs_object_id',
        'last_error_aspel',
        'last_sync_aspel',
        'objectId',
        'portalId',
        'propertyName',
        'propertyValue',
        'source_event_id',
        'subscriptionType',
        'sync_status_aspel',
        'sync_to_aspel',
        'versionSinc',
        'version_sinc',
        'VERSION_SINC',
        'cursor_context',
    ];

    public function __construct(
        Platform $platform,
        AuthStrategyResolver $authStrategyResolver,
        ?Event $event = null,
        ?Record $record = null,
        protected ?AspelApiService $aspelApiService = null
    ) {
        parent::__construct($platform, $event, $record, $authStrategyResolver);

        $this->aspelApiService ??= app(AspelApiService::class);
    }

    public function resolveBody(Event $event, array $payload): array
    {
        $body = parent::resolveBody($event, $payload);

        foreach (self::TECHNICAL_KEYS as $key) {
            Arr::forget($body, $key);
        }

        return array_filter(
            $body,
            static fn (mixed $value): bool => $value !== null
        );
    }

    public function createContact(array $payload, ?GenericHttpAdapter $httpAdapter = null): array
    {
        return $this->sendContactRequest('create', $payload, $httpAdapter);
    }

    public function updateContact(array $payload, ?GenericHttpAdapter $httpAdapter = null): array
    {
        $clave = $this->resolveAspelClave($payload);
        if ($clave === null) {
            return [
                'success' => false,
                'message' => 'ASPEL contact update requires clave.',
                'data' => [
                    'required_context' => ['clave'],
                    'received_keys' => array_keys($payload),
                ],
            ];
        }

        return $this->sendContactRequest('update', $payload, $httpAdapter, $clave);
    }

    public function findContact(array $payload, ?GenericHttpAdapter $httpAdapter = null): array
    {
        $query = $this->buildSearchQuery($payload);
        if ($query === []) {
            return [
                'success' => false,
                'message' => 'ASPEL contact search requires at least one lookup field.',
                'data' => [
                    'required_context' => ['clave', 'rfc', 'phone', 'email'],
                    'received_keys' => array_keys($payload),
                ],
            ];
        }

        return $this->sendRequest(
            $this->resolveOperationEndpoint('search', $payload),
            $this->resolveOperationHttpMethod('search'),
            ['query' => $query],
            $httpAdapter
        );
    }

    public function updateContactWithLookup(array $payload, ?GenericHttpAdapter $httpAdapter = null): array
    {
        $lookupCriteria = $this->buildSearchQuery($payload);
        $lookupResponse = $this->findContact($payload, $httpAdapter);
        $this->logAspelLookupResult($lookupCriteria, $lookupResponse, $payload);

        if (! ($lookupResponse['success'] ?? false) && ! $this->isLookupNotFoundResponse($lookupResponse)) {
            return [
                'success' => false,
                'message' => 'Failed to lookup ASPEL contact before update.',
                'data' => [
                    'lookup_response' => $lookupResponse,
                    'lookup_criteria' => $lookupCriteria,
                ],
            ];
        }

        $lookupData = $this->isLookupNotFoundResponse($lookupResponse)
            ? [
                'found' => false,
                'count' => 0,
                'criteriaUsed' => Arr::get($lookupResponse, 'data.criteriaUsed'),
                'item' => null,
                'items' => null,
            ]
            : Arr::get($lookupResponse, 'data', []);
        $count = (int) Arr::get($lookupData, 'count', 0);
        $found = (bool) Arr::get($lookupData, 'found', false);
        $criteriaUsed = Arr::get($lookupData, 'criteriaUsed');

        if ($found && $count === 1) {
            $matchItem = Arr::get($lookupData, 'item', []);
            $clave = $this->resolveAspelClave(is_array($matchItem) ? $matchItem : []);

            if ($clave === null) {
                return [
                    'success' => false,
                    'message' => 'ASPEL contact lookup matched a record without clave.',
                    'data' => [
                        'lookup_response' => $lookupResponse,
                        'lookup_criteria' => $lookupCriteria,
                    ],
                ];
            }

            $updatePayload = array_merge($payload, [
                'clave' => $clave,
                'aspel_lookup' => [
                    'found' => true,
                    'count' => $count,
                    'criteriaUsed' => $criteriaUsed,
                    'item' => $matchItem,
                ],
            ]);

            $updateResponse = $this->updateContact($updatePayload, $httpAdapter);

            if (! ($updateResponse['success'] ?? false)) {
                return [
                        'success' => false,
                        'message' => 'ASPEL contact update failed after successful lookup.',
                        'data' => [
                            'lookup_response' => $lookupResponse,
                            'update_response' => $updateResponse,
                            'lookup_criteria' => $lookupCriteria,
                        ],
                    ];
            }

            $updateResponse['data'] = array_merge(
                is_array($updateResponse['data'] ?? null) ? $updateResponse['data'] : [],
                [
                    'updated_count' => 1,
                    'not_found_count' => 0,
                    'matched_by' => $criteriaUsed,
                    'lookup_response' => $lookupData,
                    'output_payload' => [],
                ]
            );

            return $updateResponse;
        }

        if ($count > 1) {
            return [
                'success' => true,
                'status' => 'warning',
                'message' => 'Multiple ASPEL contacts matched the provided lookup criteria.',
                'data' => [
                    'updated_count' => 0,
                    'not_found_count' => 0,
                    'warning_reason' => 'multiple_matches',
                    'lookup_criteria' => $lookupCriteria,
                    'lookup_response' => $lookupData,
                    'output_payload' => [],
                ],
            ];
        }

        $hasFallbackEvent = (bool) ($this->event?->to_event_id);
        $status = $hasFallbackEvent ? null : 'warning';
        $message = $hasFallbackEvent
            ? 'ASPEL contact was not found for update; create fallback payload prepared.'
            : 'ASPEL contact was not found for update and no create fallback event is configured.';

        return [
            'success' => true,
            'status' => $status,
            'message' => $message,
            'data' => [
                'updated_count' => 0,
                'not_found_count' => 1,
                'warning_reason' => $hasFallbackEvent ? null : 'missing_create_fallback_event',
                'matched_by' => null,
                'lookup_criteria' => $lookupCriteria,
                'lookup_response' => $lookupData,
                'output_payload' => $payload,
            ],
        ];
    }

    private function isLookupNotFoundResponse(array $lookupResponse): bool
    {
        if ((int) Arr::get($lookupResponse, 'status_code', 0) !== 404) {
            return false;
        }

        $message = $this->resolveScalarValue([
            Arr::get($lookupResponse, 'error.message'),
            Arr::get($lookupResponse, 'data.error'),
            Arr::get($lookupResponse, 'message'),
        ]);

        if ($message === null) {
            return false;
        }

        return str_contains(Str::lower($message), 'contact not found');
    }

    public function getUpdatedContacts(array $payload = [], ?GenericHttpAdapter $httpAdapter = null): array
    {
        if (! $this->event) {
            return [
                'success' => false,
                'message' => 'ASPEL schedule context is required for contact change polling.',
                'data' => [],
            ];
        }

        if (! $this->record) {
            return $this->sendRequest(
                $this->resolveOperationEndpoint('poll', []),
                $this->resolveOperationHttpMethod('poll'),
                $payload,
                $httpAdapter
            );
        }

        $take = max(1, (int) ($this->event->meta['take'] ?? Arr::get($payload, 'take', self::DEFAULT_CHANGES_TAKE)));
        $cursor = $this->loadCursorState();
        $runStartedAt = now()->toISOString();
\Log::info('Starting ASPEL contact change polling run', ['take' => $take, 'initial_cursor' => $cursor, 'payload' => $payload, 'run_started_at' => $runStartedAt]);
        $metrics = [
            'take' => $take,
            'pages_processed' => 0,
            'items_seen' => 0,
            'items_processed' => 0,
            'items_skipped' => 0,
            'items_failed' => 0,
        ];

        $this->persistRunState([
            'last_run_started_at' => $runStartedAt,
            'last_run_status' => 'running',
            'last_error' => null,
        ]);

        $currentCursor = [
            'sinceTs' => $cursor['sinceTs'],
            'sinceClave' => $cursor['sinceClave'],
        ];

        try {
            do {
                $pageResponse = $this->fetchChangesPage($currentCursor, $take, $httpAdapter);
                if (! ($pageResponse['success'] ?? false)) {
                    return $this->failPollingRun(
                        'Failed to fetch changed contacts from ASPEL.',
                        $pageResponse,
                        $currentCursor,
                        $metrics
                    );
                }

                $items = Arr::get($pageResponse, 'data.items', []);
                if (! is_array($items)) {
                    return $this->failPollingRun(
                        'Invalid ASPEL changes payload: items must be an array.',
                        $pageResponse,
                        $currentCursor,
                        $metrics
                    );
                }

                $nextSinceTs = $this->normalizeCursorValue(Arr::get($pageResponse, 'data.nextSinceTs'));
                $nextSinceClave = $this->normalizeCursorValue(Arr::get($pageResponse, 'data.nextSinceClave'));
                $hasMore = (bool) Arr::get($pageResponse, 'data.hasMore', false);

                foreach ($items as $item) {
                    if (! is_array($item)) {
                        return $this->failPollingRun(
                            'Invalid ASPEL changes payload: change item must be an array.',
                            $pageResponse,
                            $currentCursor,
                            $metrics
                        );
                    }

                    $metrics['items_seen']++;
                    $changeIdempotency = $this->acquireChangeIdempotency($item, 'contacts');

                    if (($changeIdempotency['skip'] ?? false) === true) {
                        $metrics['items_skipped']++;
                        continue;
                    }

                    $clave = $this->resolveAspelClave($item);
                    $detailResponse = $this->getContactDetailByClaveWithRetry((string) $clave, $httpAdapter);

                    if (! ($detailResponse['success'] ?? false)) {
                        $this->updateChangeIdempotencyStatus($changeIdempotency['model'] ?? null, 'failed', [
                            'reason' => 'detail_fetch_failed',
                            'error' => $detailResponse['error'] ?? null,
                            'status_code' => $detailResponse['status_code'] ?? null,
                        ]);

                        $metrics['items_failed']++;

                        return $this->failPollingRun(
                            'Failed to fetch ASPEL contact detail.',
                            [
                            'changes_response' => $pageResponse,
                            'detail_response' => $detailResponse,
                            'failed_item' => $item,
                            'detail_retry_attempts' => $detailResponse['retry_attempts'] ?? 0,
                        ],
                        $currentCursor,
                        $metrics
                        );
                    }

                    $normalizedPayload = $this->buildHubspotSyncPayload(
                        $item,
                        Arr::get($detailResponse, 'data', []),
                        $currentCursor,
                        [
                            'sinceTs' => $nextSinceTs,
                            'sinceClave' => $nextSinceClave,
                        ],
                        $hasMore
                    );

                    $syncResult = $this->processHubspotChangeSynchronously($normalizedPayload);
                    if (! ($syncResult['success'] ?? false)) {
                        $this->updateChangeIdempotencyStatus($changeIdempotency['model'] ?? null, 'failed', [
                            'reason' => 'hubspot_sync_failed',
                            'record_id' => $syncResult['record_id'] ?? null,
                            'message' => $syncResult['message'] ?? null,
                        ]);

                        $metrics['items_failed']++;

                        return $this->failPollingRun(
                            'Failed to sync ASPEL contact change to HubSpot.',
                            [
                                'changes_response' => $pageResponse,
                                'detail_response' => $detailResponse,
                                'failed_item' => $item,
                                'hubspot_sync' => $syncResult,
                            ],
                            $currentCursor,
                            $metrics
                        );
                    }

                    $this->updateChangeIdempotencyStatus($changeIdempotency['model'] ?? null, 'success', [
                        'clave' => $normalizedPayload['clave'] ?? null,
                        'version_sinc' => $normalizedPayload['versionSinc'] ?? null,
                        'record_id' => $syncResult['record_id'] ?? null,
                        'hubspot_contact_id' => Arr::get($syncResult, 'data.contact_id'),
                    ]);

                    $metrics['items_processed']++;
                }

                $metrics['pages_processed']++;
                $currentCursor = [
                    'sinceTs' => $nextSinceTs ?: $currentCursor['sinceTs'],
                    'sinceClave' => $nextSinceClave ?: $currentCursor['sinceClave'],
                ];

                $this->persistCursorState($currentCursor);
            } while ($hasMore);
        } catch (\Throwable $exception) {
            return $this->failPollingRun(
                'ASPEL contact change polling failed unexpectedly.',
                [
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
                $currentCursor,
                $metrics
            );
        }

        $runFinishedAt = now()->toISOString();
        $this->persistRunState([
            'last_run_finished_at' => $runFinishedAt,
            'last_run_status' => 'success',
            'last_error' => null,
        ]);

        $data = [
            'cursor' => [
                'sinceTs' => $currentCursor['sinceTs'],
                'sinceClave' => $currentCursor['sinceClave'],
            ],
            'metrics' => $metrics,
            'last_run_started_at' => $runStartedAt,
            'last_run_finished_at' => $runFinishedAt,
            'output_payload' => [],
        ];

        $this->mergeRecordDetails([
            'service_output' => $data,
            'cursor_state' => $data['cursor'],
            'polling_metrics' => $metrics,
        ]);

        return [
            'success' => true,
            'message' => 'ASPEL contact changes processed successfully.',
            'data' => $data,
        ];
    }

    public function getUpdatedProducts(array $payload = [], ?GenericHttpAdapter $httpAdapter = null): array
    {
        if (! $this->event) {
            return [
                'success' => false,
                'message' => 'ASPEL schedule context is required for product change polling.',
                'data' => [],
            ];
        }

        if (! $this->record) {
            return $this->sendRequest(
                $this->resolveOperationEndpoint('poll', []),
                $this->resolveOperationHttpMethod('poll'),
                $payload,
                $httpAdapter
            );
        }

        $take = max(1, (int) ($this->event->meta['take'] ?? Arr::get($payload, 'take', self::DEFAULT_CHANGES_TAKE)));
        $cursor = $this->loadCursorStateForScope('products');
        $runStartedAt = now()->toISOString();
        Log::info('Starting ASPEL product change polling run', [
            'take' => $take,
            'initial_cursor' => $cursor,
            'payload' => $payload,
            'run_started_at' => $runStartedAt,
        ]);

        $metrics = [
            'take' => $take,
            'pages_processed' => 0,
            'items_seen' => 0,
            'items_processed' => 0,
            'items_skipped' => 0,
            'items_failed' => 0,
        ];

        $this->persistRunStateForScope('products', [
            'last_run_started_at' => $runStartedAt,
            'last_run_status' => 'running',
            'last_error' => null,
        ]);

        $currentCursor = [
            'sinceTs' => $cursor['sinceTs'],
            'sinceClave' => $cursor['sinceClave'],
        ];

        try {
            do {
                $pageResponse = $this->fetchChangesPage($currentCursor, $take, $httpAdapter);
                if (! ($pageResponse['success'] ?? false)) {
                    return $this->failPollingRunForScope(
                        'products',
                        'Failed to fetch changed products from ASPEL.',
                        $pageResponse,
                        $currentCursor,
                        $metrics
                    );
                }

                $items = Arr::get($pageResponse, 'data.items', []);
                if (! is_array($items)) {
                    return $this->failPollingRunForScope(
                        'products',
                        'Invalid ASPEL product changes payload: items must be an array.',
                        $pageResponse,
                        $currentCursor,
                        $metrics
                    );
                }

                $nextSinceTs = $this->normalizeCursorValue(Arr::get($pageResponse, 'data.nextSinceTs'));
                $nextSinceClave = $this->normalizeCursorValue(Arr::get($pageResponse, 'data.nextSinceClave'));
                $hasMore = (bool) Arr::get($pageResponse, 'data.hasMore', false);

                foreach ($items as $item) {
                    if (! is_array($item)) {
                        return $this->failPollingRunForScope(
                            'products',
                            'Invalid ASPEL product changes payload: change item must be an array.',
                            $pageResponse,
                            $currentCursor,
                            $metrics
                        );
                    }

                    $metrics['items_seen']++;
                    $changeIdempotency = $this->acquireChangeIdempotency($item, 'products');

                    if (($changeIdempotency['skip'] ?? false) === true) {
                        $metrics['items_skipped']++;
                        continue;
                    }

                    $clave = $this->resolveAspelClave($item);
                    $detailResponse = $this->getProductDetailByClaveWithRetry((string) $clave, $httpAdapter);

                    if (! ($detailResponse['success'] ?? false)) {
                        $this->updateChangeIdempotencyStatus($changeIdempotency['model'] ?? null, 'failed', [
                            'reason' => 'detail_fetch_failed',
                            'error' => $detailResponse['error'] ?? null,
                            'status_code' => $detailResponse['status_code'] ?? null,
                        ]);

                        $metrics['items_failed']++;

                        return $this->failPollingRunForScope(
                            'products',
                            'Failed to fetch ASPEL product detail.',
                            [
                                'changes_response' => $pageResponse,
                                'detail_response' => $detailResponse,
                                'failed_item' => $item,
                                'detail_retry_attempts' => $detailResponse['retry_attempts'] ?? 0,
                            ],
                            $currentCursor,
                            $metrics
                        );
                    }

                    $normalizedPayload = $this->buildHubspotProductSyncPayload(
                        $item,
                        Arr::get($detailResponse, 'data', []),
                        $currentCursor,
                        [
                            'sinceTs' => $nextSinceTs,
                            'sinceClave' => $nextSinceClave,
                        ],
                        $hasMore
                    );

                    $syncResult = $this->processHubspotProductChangeSynchronously($normalizedPayload);
                    if (! ($syncResult['success'] ?? false)) {
                        $this->updateChangeIdempotencyStatus($changeIdempotency['model'] ?? null, 'failed', [
                            'reason' => 'hubspot_sync_failed',
                            'record_id' => $syncResult['record_id'] ?? null,
                            'message' => $syncResult['message'] ?? null,
                        ]);

                        $metrics['items_failed']++;

                        return $this->failPollingRunForScope(
                            'products',
                            'Failed to sync ASPEL product change to HubSpot.',
                            [
                                'changes_response' => $pageResponse,
                                'detail_response' => $detailResponse,
                                'failed_item' => $item,
                                'hubspot_sync' => $syncResult,
                            ],
                            $currentCursor,
                            $metrics
                        );
                    }

                    $this->updateChangeIdempotencyStatus($changeIdempotency['model'] ?? null, 'success', [
                        'clave' => $normalizedPayload['clave'] ?? null,
                        'version_sinc' => $normalizedPayload['versionSinc'] ?? null,
                        'record_id' => $syncResult['record_id'] ?? null,
                        'hubspot_product_id' => Arr::get($syncResult, 'data.product_id'),
                    ]);

                    $metrics['items_processed']++;
                }

                $metrics['pages_processed']++;
                $currentCursor = [
                    'sinceTs' => $nextSinceTs ?: $currentCursor['sinceTs'],
                    'sinceClave' => $nextSinceClave ?: $currentCursor['sinceClave'],
                ];

                $this->persistCursorStateForScope('products', $currentCursor);
            } while ($hasMore);
        } catch (\Throwable $exception) {
            return $this->failPollingRunForScope(
                'products',
                'ASPEL product change polling failed unexpectedly.',
                [
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
                $currentCursor,
                $metrics
            );
        }

        $runFinishedAt = now()->toISOString();
        $this->persistRunStateForScope('products', [
            'last_run_finished_at' => $runFinishedAt,
            'last_run_status' => 'success',
            'last_error' => null,
        ]);

        $data = [
            'cursor' => [
                'sinceTs' => $currentCursor['sinceTs'],
                'sinceClave' => $currentCursor['sinceClave'],
            ],
            'metrics' => $metrics,
            'last_run_started_at' => $runStartedAt,
            'last_run_finished_at' => $runFinishedAt,
            'output_payload' => [],
        ];

        $this->mergeRecordDetails([
            'service_output' => $data,
            'cursor_state' => $data['cursor'],
            'polling_metrics' => $metrics,
        ]);

        return [
            'success' => true,
            'message' => 'ASPEL product changes processed successfully.',
            'data' => $data,
        ];
    }

    public function getContactDetailByClave(string $clave, ?GenericHttpAdapter $httpAdapter = null): array
    {
        $normalizedClave = trim($clave);
        if ($normalizedClave === '') {
            return [
                'success' => false,
                'message' => 'ASPEL contact detail requires clave.',
                'data' => [],
                'error' => [
                    'code' => 'missing_clave',
                    'message' => 'ASPEL contact detail requires clave.',
                    'details' => null,
                ],
                'status_code' => 0,
            ];
        }

        return $this->sendRequest(
            $this->resolveDetailEndpoint($normalizedClave),
            'GET',
            [],
            $httpAdapter
        );
    }

    public function getProductDetailByClave(string $clave, ?GenericHttpAdapter $httpAdapter = null): array
    {
        $normalizedClave = trim($clave);
        if ($normalizedClave === '') {
            return [
                'success' => false,
                'message' => 'ASPEL product detail requires clave.',
                'data' => [],
                'error' => [
                    'code' => 'missing_clave',
                    'message' => 'ASPEL product detail requires clave.',
                    'details' => null,
                ],
                'status_code' => 0,
            ];
        }

        return $this->sendRequest(
            $this->resolveDetailEndpoint($normalizedClave),
            'GET',
            [],
            $httpAdapter
        );
    }

    private function getContactDetailByClaveWithRetry(string $clave, ?GenericHttpAdapter $httpAdapter = null): array
    {
        $attempt = 0;
        $lastResponse = [];

        do {
            $attempt++;
            $response = $this->getContactDetailByClave($clave, $httpAdapter);
            $lastResponse = $response;

            if (($response['success'] ?? false) === true) {
                $response['retry_attempts'] = $attempt;

                return $response;
            }

            if (($response['status_code'] ?? null) !== 404) {
                $response['retry_attempts'] = $attempt;

                return $response;
            }

            $backoffSeconds = self::DETAIL_NOT_FOUND_RETRY_BACKOFF_SECONDS[$attempt - 1] ?? null;
            if ($backoffSeconds === null) {
                break;
            }

            if (! app()->environment('testing')) {
                sleep($backoffSeconds);
            }
        } while (true);

        $lastResponse['retry_attempts'] = $attempt;
        $lastResponse['retry_exhausted'] = true;

        return $lastResponse;
    }

    private function getProductDetailByClaveWithRetry(string $clave, ?GenericHttpAdapter $httpAdapter = null): array
    {
        $attempt = 0;
        $lastResponse = [];

        do {
            $attempt++;
            $response = $this->getProductDetailByClave($clave, $httpAdapter);
            $lastResponse = $response;

            if (($response['success'] ?? false) === true) {
                $response['retry_attempts'] = $attempt;

                return $response;
            }

            if (($response['status_code'] ?? null) !== 404) {
                $response['retry_attempts'] = $attempt;

                return $response;
            }

            $backoffSeconds = self::DETAIL_NOT_FOUND_RETRY_BACKOFF_SECONDS[$attempt - 1] ?? null;
            if ($backoffSeconds === null) {
                break;
            }

            if (! app()->environment('testing')) {
                sleep($backoffSeconds);
            }
        } while (true);

        $lastResponse['retry_attempts'] = $attempt;
        $lastResponse['retry_exhausted'] = true;

        return $lastResponse;
    }

    public function executeEndpointCall(array $payload, GenericHttpAdapter $httpAdapter): array
    {
        $method = $this->resolveOperationMethod();

        if ($method && method_exists($this, $method)) {
            return $this->{$method}($payload, $httpAdapter);
        }

        return parent::executeEndpointCall($payload, $httpAdapter);
    }

    private function resolveOperationMethod(): ?string
    {
        $candidate = $this->event?->method_name
            ?? $this->event?->meta['operation']
            ?? null;

        if (! is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        $value = trim($candidate);
        if (str_contains($value, '_')) {
            $value = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $value))));
        }

        return $value;
    }

    private function sendContactRequest(
        string $operation,
        array $payload,
        ?GenericHttpAdapter $httpAdapter = null,
        ?string $clave = null
    ): array {
        if (! $this->event) {
            return [
                'success' => false,
                'message' => 'ASPEL event context is required for contact sync.',
                'data' => [],
            ];
        }

        return $this->sendRequest(
            $this->resolveOperationEndpoint($operation, $payload, $clave),
            $this->resolveOperationHttpMethod($operation),
            $this->preparePayloadForOperation($operation, $payload),
            $httpAdapter
        );
    }

    private function sendRequest(
        string $endpoint,
        string $method,
        array $payload,
        ?GenericHttpAdapter $httpAdapter = null
    ): array {
        $normalizedMethod = strtoupper($method);
        $body = in_array($normalizedMethod, ['GET', 'DELETE'], true)
            ? []
            : $this->resolveBody($this->event, $payload);

        return $this->aspelApiService->send(
            'aspel',
            $endpoint,
            $normalizedMethod,
            $this->resolveHeaders($this->event, $this->platform),
            $this->resolveQueryParams($this->event, $payload),
            $body,
            $this->resolveTimeout($this->event),
            $this->resolveRetryPolicy($this->event),
            $httpAdapter
        );
    }

    private function fetchChangesPage(array $cursor, int $take, ?GenericHttpAdapter $httpAdapter = null): array
    {
        return $this->sendRequest(
            $this->resolveOperationEndpoint('poll', []),
            $this->resolveOperationHttpMethod('poll'),
            [
                'query' => [
                    'sinceTs' => $cursor['sinceTs'],
                    'sinceClave' => $cursor['sinceClave'],
                    'take' => $take,
                ],
            ],
            $httpAdapter
        );
    }

    private function resolveOperationEndpoint(string $operation, array $payload, ?string $clave = null): string
    {
        $endpoint = $this->resolveEndpoint($this->event);

        return match ($operation) {
            'search' => $this->resolveSearchEndpoint($endpoint),
            'update' => $this->normalizeUpdateEndpoint($endpoint, $clave ?? $this->resolveAspelClave($payload)),
            default => $endpoint,
        };
    }

    private function resolveOperationHttpMethod(string $operation): string
    {
        return match ($operation) {
            'create' => 'POST',
            'search' => 'GET',
            'update' => 'PUT',
            'poll' => 'GET',
            default => $this->resolveMethod($this->event),
        };
    }

    private function normalizeUpdateEndpoint(string $endpoint, ?string $clave): string
    {
        if ($clave === null || trim($clave) === '') {
            return $endpoint;
        }

        $normalizedClave = trim($clave);

        if (preg_match('/\{[^}]+\}/', $endpoint) === 1) {
            return preg_replace('/\{[^}]+\}/', rawurlencode($normalizedClave), $endpoint, 1) ?: $endpoint;
        }

        if (preg_match('~/contacts/?$~i', $endpoint) === 1) {
            return rtrim($endpoint, '/') . '/' . rawurlencode($normalizedClave);
        }

        return $endpoint;
    }

    private function resolveDetailEndpoint(string $clave): string
    {
        $configured = $this->event?->meta['detail_endpoint'] ?? null;
        if (is_string($configured) && trim($configured) !== '') {
            $endpoint = trim($configured);
        } else {
            $endpoint = $this->resolveOperationEndpoint('poll', []);
            $endpoint = preg_replace('~/changes/?$~i', '', $endpoint) ?: $endpoint;
        }

        if (str_contains($endpoint, '{clave}')) {
            return str_replace('{clave}', rawurlencode($clave), $endpoint);
        }

        return rtrim($endpoint, '/') . '/' . rawurlencode($clave);
    }

    private function resolveSearchEndpoint(string $endpoint): string
    {
        $configured = $this->event?->meta['search_endpoint'] ?? null;
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $endpoint = preg_replace('/\{[^}]+\}/', '', $endpoint) ?: $endpoint;

        if (preg_match('~/contacts/search/?$~i', $endpoint) === 1) {
            return $endpoint;
        }

        if (preg_match('~/contacts/upsert/?$~i', $endpoint) === 1) {
            $endpoint = preg_replace('~/upsert/?$~i', '', $endpoint) ?: $endpoint;
        }

        $endpoint = preg_replace('~/contacts/[^/]+/?$~i', '/contacts', $endpoint) ?: $endpoint;

        if (preg_match('~/contacts/?$~i', $endpoint) === 1) {
            return rtrim($endpoint, '/') . '/search';
        }

        return rtrim($endpoint, '/') . '/search';
    }

    private function resolveAspelClave(array $payload): ?string
    {
        $candidates = [
            Arr::get($payload, 'clave'),
            Arr::get($payload, 'CLAVE'),
            Arr::get($payload, 'destination_response.data.clave'),
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

    private function preparePayloadForOperation(string $operation, array $payload): array
    {
        if ($operation !== 'update') {
            return $payload;
        }

        unset($payload['clave'], $payload['CLAVE']);

        return $payload;
    }

    private function logAspelLookupResult(array $lookupCriteria, array $lookupResponse, array $payload): void
    {
        $summary = [
            'event_id' => $this->event?->id,
            'record_id' => $this->record?->id,
            'lookup_criteria' => $lookupCriteria,
            'resolved_clave' => $this->resolveAspelClave($payload),
            'response_success' => $lookupResponse['success'] ?? null,
            'response_status_code' => $lookupResponse['status_code'] ?? null,
            'response_found' => Arr::get($lookupResponse, 'data.found'),
            'response_count' => Arr::get($lookupResponse, 'data.count'),
            'response_criteria_used' => Arr::get($lookupResponse, 'data.criteriaUsed'),
            'response_item' => Arr::get($lookupResponse, 'data.item'),
            'response_items' => Arr::get($lookupResponse, 'data.items'),
            'response_error' => $lookupResponse['error'] ?? null,
        ];

        Log::info('ASPEL contact lookup executed.', $summary);

        $this->mergeRecordDetails([
            'aspel_lookup' => $summary,
        ]);
    }

    private function resolveAspelVersionSinc(array $payload): ?string
    {
        $candidates = [
            Arr::get($payload, 'versionSinc'),
            Arr::get($payload, 'version_sinc'),
            Arr::get($payload, 'VERSION_SINC'),
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

    /**
     * @return array<string, string>
     */
    private function buildSearchQuery(array $payload): array
    {
        $query = [];

        foreach ([
            'clave' => $this->resolveAspelClave($payload),
            'rfc' => $this->resolveScalarValue([
                Arr::get($payload, 'rfc'),
                Arr::get($payload, 'Rfc'),
                Arr::get($payload, 'RFC'),
            ]),
            'phone' => $this->resolveScalarValue([
                Arr::get($payload, 'phone'),
                Arr::get($payload, 'telefono'),
                Arr::get($payload, 'Telefono'),
            ]),
            'email' => $this->resolveScalarValue([
                Arr::get($payload, 'email'),
                Arr::get($payload, 'emailEnvio'),
                Arr::get($payload, 'Email'),
            ]),
        ] as $key => $value) {
            if ($value !== null) {
                $query[$key] = $value;
            }
        }

        return $query;
    }

    private function resolveScalarValue(array $candidates): ?string
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

    private function loadCursorState(): array
    {
        return $this->loadCursorStateForScope('contacts');
    }

    private function persistCursorState(array $cursor): void
    {
        $this->persistCursorStateForScope('contacts', $cursor);
    }

    private function persistRunState(array $state): void
    {
        $this->persistRunStateForScope('contacts', $state);
    }

    private function loadCursorStateForScope(string $scope): array
    {
        Log::info('Loading ASPEL sync cursor state.', [
            'scope' => $scope,
            'sinceTs' => $this->normalizeCursorValue($this->getCursorValue($suffix = 'since_ts', $scope)),
            'sinceClave' => $this->normalizeCursorValue($this->getCursorValue('since_clave', $scope)),
        ]);

        return [
            'sinceTs' => $this->normalizeCursorValue($this->getCursorValue('since_ts', $scope))
                ?? now()->subHours(self::DEFAULT_INITIAL_LOOKBACK_HOURS)->toISOString(),
            'sinceClave' => $this->normalizeCursorValue($this->getCursorValue('since_clave', $scope)) ?? '',
        ];
    }

    private function persistCursorStateForScope(string $scope, array $cursor): void
    {
        $this->setCursorValue('since_ts', $cursor['sinceTs'] ?? null, 'ASPEL ' . $scope . ' sync cursor timestamp', $scope);
        $this->setCursorValue('since_clave', $cursor['sinceClave'] ?? '', 'ASPEL ' . $scope . ' sync cursor clave', $scope);
    }

    private function persistRunStateForScope(string $scope, array $state): void
    {
        if (array_key_exists('last_run_started_at', $state)) {
            $this->setCursorValue('last_run_started_at', $state['last_run_started_at'], 'ASPEL ' . $scope . ' sync last run start', $scope);
        }

        if (array_key_exists('last_run_finished_at', $state)) {
            $this->setCursorValue('last_run_finished_at', $state['last_run_finished_at'], 'ASPEL ' . $scope . ' sync last run finish', $scope);
        }

        if (array_key_exists('last_run_status', $state)) {
            $this->setCursorValue('last_run_status', $state['last_run_status'], 'ASPEL ' . $scope . ' sync last run status', $scope);
        }

        if (array_key_exists('last_error', $state)) {
            $this->setCursorValue('last_error', $state['last_error'], 'ASPEL ' . $scope . ' sync last error', $scope);
        }
    }

    private function getCursorValue(string $suffix, string $scope = 'contacts'): mixed
    {
        $config = Config::query()
            ->where('key', $this->cursorConfigKey($suffix, $scope))
            ->first();

        if (! $config) {
            return null;
        }

        $value = $config->value;

        if (! is_array($value)) {
            return $value;
        }

        return $value['value'] ?? $value;
    }

    private function setCursorValue(string $suffix, mixed $value, ?string $description = null, string $scope = 'contacts'): void
    {
        Config::query()->updateOrCreate(
            ['key' => $this->cursorConfigKey($suffix, $scope)],
            [
                'value' => ['value' => $value],
                'description' => $description,
                'is_encrypted' => false,
            ]
        );
    }

    private function cursorConfigKey(string $suffix, string $scope = 'contacts'): string
    {
        return 'aspel.' . $scope . '.cursor.' . $this->event->id . '.' . $suffix;
    }

    private function normalizeCursorValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function acquireChangeIdempotency(array $item, string $scope = 'contacts'): array
    {
        $clave = $this->resolveAspelClave($item);
        $versionSinc = $this->resolveAspelVersionSinc($item);

        if ($clave === null || $versionSinc === null) {
            return [
                'skip' => false,
                'model' => null,
            ];
        }

        $key = $this->buildChangeIdempotencyKey($scope, $clave, $versionSinc);
        $expiresAt = now()->addHours(self::CHANGE_IDEMPOTENCY_TTL_HOURS);
        $staleBefore = now()->subMinutes(self::CHANGE_IDEMPOTENCY_STALE_MINUTES);

        $state = DB::transaction(function () use ($key, $expiresAt, $staleBefore, $clave, $versionSinc, $scope): array {
            $existing = EventIdempotencyKey::query()
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                $created = EventIdempotencyKey::query()->create([
                    'idempotency_key' => $key,
                    'event_id' => $this->event->id,
                    'record_id' => $this->record->id,
                    'endpoint' => 'aspel.' . $scope . '.changes',
                    'method' => 'CHANGE',
                    'status' => 'processing',
                    'expires_at' => $expiresAt,
                    'metadata' => [
                        'source' => 'aspel_' . $scope . '_changes',
                        'clave' => $clave,
                        'versionSinc' => $versionSinc,
                    ],
                ]);

                return ['state' => 'acquired', 'model' => $created];
            }

            $isExpired = $existing->expires_at?->isPast() ?? false;
            $isStaleProcessing = $existing->status === 'processing'
                && (($existing->updated_at?->lt($staleBefore)) ?? false);

            if (! $isExpired && $existing->status === 'success') {
                return ['state' => 'already_processed', 'model' => $existing];
            }

            if (! $isExpired && $existing->status === 'processing' && ! $isStaleProcessing) {
                return ['state' => 'already_processing', 'model' => $existing];
            }

            $metadata = is_array($existing->metadata) ? $existing->metadata : [];
            $existing->update([
                'event_id' => $this->event->id,
                'record_id' => $this->record->id,
                'endpoint' => 'aspel.' . $scope . '.changes',
                'method' => 'CHANGE',
                'status' => 'processing',
                'expires_at' => $expiresAt,
                'metadata' => array_merge($metadata, [
                    'source' => 'aspel_' . $scope . '_changes',
                    'clave' => $clave,
                    'versionSinc' => $versionSinc,
                ]),
            ]);

            return ['state' => 'acquired', 'model' => $existing->fresh()];
        });

        return [
            'skip' => in_array($state['state'], ['already_processed', 'already_processing'], true),
            'model' => $state['model'],
        ];
    }

    private function updateChangeIdempotencyStatus(?EventIdempotencyKey $model, string $status, array $metadata = []): void
    {
        if (! $model) {
            return;
        }

        $existingMetadata = is_array($model->metadata) ? $model->metadata : [];
        $model->update([
            'status' => $status,
            'metadata' => array_merge($existingMetadata, $metadata),
        ]);
    }

    private function buildChangeIdempotencyKey(string $scope, string $clave, string $versionSinc): string
    {
        return 'evt:' . $this->event->id . ':aspel-' . $scope . '-change:' . Str::lower(sha1($clave . '|' . $versionSinc));
    }

    private function buildHubspotSyncPayload(
        array $item,
        array $detail,
        array $currentCursor,
        array $nextCursor,
        bool $hasMore
    ): array {
        $phone = Arr::get($detail, 'phone') ?? Arr::get($detail, 'telefono');
        $email = Arr::get($detail, 'email') ?? Arr::get($detail, 'emailEnvio');

        return [
            'source_platform' => 'aspel',
            'source_event_id' => $this->event->id,
            'clave' => $this->resolveAspelClave($item),
            'rfc' => Arr::get($item, 'rfc') ?? Arr::get($detail, 'rfc'),
            'status' => Arr::get($item, 'status') ?? Arr::get($detail, 'status'),
            'versionSinc' => $this->resolveAspelVersionSinc($item),
            'phone' => $phone,
            'email' => $email,
            'aspel_detail' => $detail,
            'cursor_context' => [
                'input' => $currentCursor,
                'next' => $nextCursor,
                'hasMore' => $hasMore,
            ],
        ];
    }

    private function processHubspotChangeSynchronously(array $payload): array
    {
        $nextEvent = $this->event->to_event;
        if (! $nextEvent) {
            return [
                'success' => false,
                'message' => 'No HubSpot event configured for ASPEL contact changes.',
                'data' => [],
            ];
        }

        return $this->processEventSynchronously($nextEvent, $payload, $this->record);
    }

    private function buildHubspotProductSyncPayload(
        array $item,
        array $detail,
        array $currentCursor,
        array $nextCursor,
        bool $hasMore
    ): array {
        return [
            'source_platform' => 'aspel',
            'source_event_id' => $this->event->id,
            'clave' => $this->resolveAspelClave($item),
            'versionSinc' => $this->resolveAspelVersionSinc($item),
            'aspel_detail' => $detail,
            'cursor_context' => [
                'input' => $currentCursor,
                'next' => $nextCursor,
                'hasMore' => $hasMore,
            ],
        ];
    }

    private function processHubspotProductChangeSynchronously(array $payload): array
    {
        $nextEvent = $this->event->to_event;
        if (! $nextEvent) {
            return [
                'success' => false,
                'message' => 'No HubSpot event configured for ASPEL product changes.',
                'data' => [],
            ];
        }

        return $this->processEventSynchronously($nextEvent, $payload, $this->record);
    }

    private function failPollingRunForScope(
        string $scope,
        string $message,
        array $context,
        array $cursor,
        array $metrics
    ): array {
        $runFinishedAt = now()->toISOString();
        $this->persistRunStateForScope($scope, [
            'last_run_finished_at' => $runFinishedAt,
            'last_run_status' => 'error',
            'last_error' => $message,
        ]);

        $details = [
            'service_output' => [
                'cursor' => [
                    'sinceTs' => $cursor['sinceTs'] ?? null,
                    'sinceClave' => $cursor['sinceClave'] ?? null,
                ],
                'metrics' => $metrics,
                'failure_context' => $context,
                'last_run_finished_at' => $runFinishedAt,
            ],
            'cursor_state' => [
                'sinceTs' => $cursor['sinceTs'] ?? null,
                'sinceClave' => $cursor['sinceClave'] ?? null,
            ],
            'polling_metrics' => $metrics,
            'failure_context' => $context,
        ];

        $this->mergeRecordDetails($details);

        return [
            'success' => false,
            'message' => $message,
            'data' => $details['service_output'],
        ];
    }

    private function processEventSynchronously(Event $event, array $payload, Record $parentRecord): array
    {
        $eventLoggingService = app(EventLoggingService::class);
        $eventProcessingService = app(EventProcessingService::class);

        $record = $eventLoggingService->createEventRecord(
            $event->event_type_id ?? $event->name,
            'init',
            $payload,
            'ASPEL contact change ready for synchronous event processing.',
            $parentRecord->id,
            $event->id
        );

        $record->update([
            'status' => 'processing',
            'message' => 'Processing synchronous ASPEL event chain',
        ]);

        $serviceClass = $eventProcessingService->getServiceClass($event->platform);
        if (! $serviceClass || ! class_exists($serviceClass)) {
            $eventLoggingService->logEventWarning($record, 'Service class not found for synchronous event processing.', [
                'reason' => 'service_class_not_found',
                'event_id' => $event->id,
                'event_type_id' => $event->event_type_id,
                'platform_type' => $event->platform?->type,
                'service_class' => $serviceClass,
            ]);

            return [
                'success' => false,
                'message' => $record->message ?: 'Service class not found for synchronous event processing.',
                'record_id' => $record->id,
                'data' => [
                    'status' => $record->status,
                    'details' => $record->details,
                ],
            ];
        }

        $service = app()->make($serviceClass, [
            'platform' => $event->platform,
            'event' => $event,
            'record' => $record,
        ]);
        $methodName = $event->getMethodName() ?? $event->method_name;

        if (! $methodName || ! method_exists($service, $methodName)) {
            $eventLoggingService->logEventWarning($record, 'Event method not available for synchronous processing.', [
                'reason' => 'method_not_available',
                'event_id' => $event->id,
                'event_type_id' => $event->event_type_id,
                'platform_type' => $event->platform?->type,
                'service_class' => $serviceClass,
                'method_name' => $methodName,
            ]);

            return [
                'success' => false,
                'message' => $record->message ?: 'Event method not available for synchronous processing.',
                'record_id' => $record->id,
                'data' => [
                    'status' => $record->status,
                    'details' => $record->details,
                ],
            ];
        }

        try {
            $result = $this->invokeServiceMethod($service, $methodName, $payload, $event, $record);
        } catch (\Throwable $exception) {
            $eventLoggingService->logEventError($record, $exception);

            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'record_id' => $record->id,
                'data' => [
                    'status' => $record->status,
                    'details' => $record->details,
                ],
            ];
        }

        $outputPayload = $this->resolveOutputPayload($result, $payload);
        $this->mergeRecordDetails([
            'service_output' => Arr::get($result, 'data', []),
            'service_message' => Arr::get($result, 'message'),
            'output_payload' => $outputPayload,
        ], $record);

        if (! Arr::get($result, 'success', false)) {
            $eventLoggingService->logEventError($record, new \RuntimeException(Arr::get($result, 'message', 'Synchronous event processing failed.')));

            return [
                'success' => false,
                'message' => $record->message ?: Arr::get($result, 'message', 'Synchronous event processing failed.'),
                'record_id' => $record->id,
                'data' => [
                    'status' => $record->status,
                    'details' => $record->details,
                ],
            ];
        }

        if (Arr::get($result, 'status') === 'warning') {
            $eventLoggingService->logEventWarning(
                $record,
                Arr::get($result, 'message', 'Synchronous event processed with warnings.'),
                Arr::get($result, 'data', [])
            );

            return [
                'success' => false,
                'message' => $record->message ?: Arr::get($result, 'message', 'Synchronous event processed with warnings.'),
                'record_id' => $record->id,
                'data' => [
                    'status' => $record->status,
                    'details' => $record->details,
                ],
            ];
        }

        $eventLoggingService->logEventSuccess($record, Arr::get($result, 'message', 'Synchronous event processed.'));

        if ($event->to_event && $this->shouldDispatchNextPayload($outputPayload)) {
            $preparedPayload = app(\App\Services\EventFlowService::class)->transformPayloadForEvent($event, $outputPayload);

            return $this->processEventSynchronously($event->to_event, $preparedPayload, $record);
        }

        return [
            'success' => true,
            'message' => $record->message,
            'record_id' => $record->id,
            'data' => is_array($record->details) ? ($record->details['service_output'] ?? []) : [],
        ];
    }

    private function invokeServiceMethod(object $service, string $methodName, array $payload, Event $event, Record $record): array
    {
        $reflection = new \ReflectionMethod($service, $methodName);
        $required = $reflection->getNumberOfRequiredParameters();

        if ($required === 0) {
            $result = $service->{$methodName}();
        } elseif ($required === 1) {
            $result = $service->{$methodName}($payload);
        } elseif ($required === 2) {
            $result = $service->{$methodName}($payload, $record);
        } else {
            $subscriptionType = $event->getSubscriptionType() ?? $event->name;
            $result = $service->{$methodName}($subscriptionType, $payload, $record);
        }

        if (is_array($result)) {
            return $result;
        }

        return [
            'success' => (bool) $result,
            'message' => $result ? 'Event processed.' : 'Event processing failed.',
            'data' => [],
        ];
    }

    private function resolveOutputPayload(array $result, array $fallback): array
    {
        $outputPayload = Arr::get($result, 'data.output_payload');

        if (is_array($outputPayload)) {
            return $outputPayload;
        }

        $data = Arr::get($result, 'data', []);

        return is_array($data) ? $data : $fallback;
    }

    private function shouldDispatchNextPayload(array $payload): bool
    {
        if ($payload === []) {
            return false;
        }

        if (array_is_list($payload)) {
            return count($payload) > 0;
        }

        return true;
    }

    private function failPollingRun(string $message, array $context, array $cursor, array $metrics): array
    {
        $runFinishedAt = now()->toISOString();
        $this->persistRunState([
            'last_run_finished_at' => $runFinishedAt,
            'last_run_status' => 'error',
            'last_error' => $message,
        ]);

        $details = [
            'cursor_state' => $cursor,
            'polling_metrics' => $metrics,
            'failure_context' => $context,
            'last_run_finished_at' => $runFinishedAt,
        ];

        $this->mergeRecordDetails($details);

        return [
            'success' => false,
            'message' => $message,
            'data' => $details,
        ];
    }

    private function mergeRecordDetails(array $details, ?Record $record = null): void
    {
        $targetRecord = $record ?? $this->record;
        if (! $targetRecord) {
            return;
        }

        $existing = is_array($targetRecord->details) ? $targetRecord->details : [];
        $targetRecord->update([
            'details' => array_replace_recursive($existing, $details),
        ]);
    }
}

<?php

namespace App\Jobs\Generic;

use App\Models\Event;
use App\Models\EventIdempotencyKey;
use App\Models\Record;
use App\Services\EventLoggingService;
use App\Services\EventProcessingService;
use App\Services\Generic\GenericHttpAdapter;
use App\Services\Generic\GenericPlatformService;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use App\Services\RateLimitService;
use App\Jobs\ProcessNextEventJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class EndpointExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 300;

    public function __construct(
        public Event $event,
        public Record $record,
        public array $payload
    ) {
        $this->onQueue('processing');
    }

    public function handle(
        EventProcessingService $eventProcessingService,
        GenericHttpAdapter $httpAdapter,
        EventLoggingService $eventLoggingService,
        RateLimitService $rateLimitService,
        ?HubspotApiServiceRefactored $hubspotApiService = null
    ): void {
        $hubspotApiService ??= app(HubspotApiServiceRefactored::class);
        $platformService = $this->resolvePlatformService($eventProcessingService);

        $this->record->update([
            'status' => 'processing',
            'message' => 'Executing generic endpoint',
        ]);

        $endpoint = $platformService->resolveEndpoint($this->event);
        $method = $platformService->resolveMethod($this->event);
        $headers = $platformService->resolveHeaders($this->event, $this->event->platform);
        $query = $platformService->resolveQueryParams($this->event, $this->payload);
        $body = $platformService->resolveBody($this->event, $this->payload);
        $timeout = $platformService->resolveTimeout($this->event);
        $retryPolicy = $platformService->resolveRetryPolicy($this->event);
        $idempotencyPolicy = $platformService->resolveIdempotencyPolicy($this->event);
        $idempotency = $this->acquireIdempotencyKey($endpoint, $method, $idempotencyPolicy, $eventLoggingService);
        if ($idempotency['skip']) {
            return;
        }
        $idempotencyModel = $idempotency['model'];

        $response = $this->normalizeExecutionResponse(
            $platformService->executeEndpointCall($this->payload, $httpAdapter),
            $endpoint,
            $method
        );

        if ($response['retryable'] && $this->attempts() < $this->tries) {
            $retryAfter = $this->extractRetryAfter($response);
            $backoffMs = $rateLimitService->computeBackoffMs($this->attempts(), $retryAfter);
            $rateLimitService->logBackoff($this->event->platform?->type ?? 'generic', $endpoint, $response['status_code'], $retryAfter, $this->attempts(), $backoffMs);
            $eventLoggingService->logEventWarning($this->record, 'Retryable response received, re-queuing job.');
            $this->updateIdempotencyStatus($idempotencyModel, 'failed_retryable', [
                'status_code' => $response['status_code'],
                'request_id' => $response['request_id'],
                'attempt' => $response['attempt'],
            ]);
            $this->release((int) ceil($backoffMs / 1000));
            return;
        }

        $recordStatus = $response['success'] ? 'success' : ($response['retryable'] ? 'warning' : 'error');
        $details = [
            'response' => $this->sanitizeResponse($response),
            'request' => [
                'endpoint' => $endpoint,
                'method' => $method,
                'headers' => $this->sanitizeHeaders($headers),
                'query' => $query,
            ],
        ];

        if (! $response['success']) {
            $details['hubspot_note'] = $this->maybeAddHubspotFailureNote(
                $response,
                $hubspotApiService
            );
        }

        $this->record->update([
            'status' => $recordStatus,
            'message' => $response['success'] ? 'Endpoint execution completed' : ($response['error']['message'] ?? 'Endpoint execution failed'),
            'details' => $details,
        ]);

        $idempotencyStatus = $response['success']
            ? 'success'
            : ($response['retryable'] ? 'failed_retryable' : 'failed');
        $this->updateIdempotencyStatus($idempotencyModel, $idempotencyStatus, [
            'status_code' => $response['status_code'],
            'request_id' => $response['request_id'],
            'attempt' => $response['attempt'],
            'record_status' => $recordStatus,
        ]);

        if ($response['success'] && $this->event->to_event) {
            ProcessNextEventJob::dispatch(
                $this->event,
                $this->record,
                $this->buildNextEventPayload($response, $endpoint, $method)
            )->onQueue('events');
        }
    }

    private function resolvePlatformService(EventProcessingService $eventProcessingService): GenericPlatformService
    {
        $serviceClass = $eventProcessingService->getServiceClass($this->event->platform);

        if (! is_string($serviceClass) || ! class_exists($serviceClass) || ! is_a($serviceClass, GenericPlatformService::class, true)) {
            $serviceClass = GenericPlatformService::class;
        }

        return app()->make($serviceClass, [
            'platform' => $this->event->platform,
            'event' => $this->event,
            'record' => $this->record,
        ]);
    }

    private function buildNextEventPayload(array $response, string $endpoint, string $method): array
    {
        $sourceEventId = Arr::get($this->payload, 'source_event_id')
            ?? Arr::get($this->payload, '_event_metadata.event_id')
            ?? $this->event->id;

        $responseData = Arr::get($response, 'data', []);
        if (! is_array($responseData)) {
            $responseData = ['raw' => $responseData];
        }

        $basePayload = Arr::get($responseData, 'output_payload');
        if (! is_array($basePayload)) {
            $basePayload = $responseData;
        }

        return array_replace_recursive($basePayload, $this->extractNextEventContext($sourceEventId), [
            'source_event_id' => $sourceEventId,
            'destination_response' => $this->sanitizeResponse($response),
            'destination_execution' => [
                'source_event_id' => $sourceEventId,
                'destination_event_id' => $this->event->id,
                'destination_platform_type' => $this->event->platform?->type,
                'endpoint' => $endpoint,
                'method' => $method,
                'processed_at' => now()->toISOString(),
            ],
        ]);
    }

    private function extractNextEventContext(int|string $sourceEventId): array
    {
        $context = [
            'source_event_id' => $sourceEventId,
        ];

        foreach ([
            'hubspot_object_id',
            'hubspot_object_type',
            'hubspotObjectId',
            'hs_object_id',
            'objectId',
            'id',
            'portalId',
            'subscriptionType',
            'propertyName',
            'propertyValue',
        ] as $key) {
            $value = Arr::get($this->payload, $key);
            if ($value !== null) {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    private function sanitizeHeaders(array $headers): array
    {
        $sensitive = config('generic-platforms.policy.sensitive_headers', []);
        $sanitized = [];

        foreach ($headers as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $sanitized[$key] = '[redacted]';
                continue;
            }
            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function sanitizeResponse(array $response): array
    {
        Arr::set($response, 'error.details', $this->sanitizeErrorDetails(Arr::get($response, 'error.details')));

        return $response;
    }

    private function normalizeExecutionResponse(array $response, string $endpoint, string $method): array
    {
        $normalized = $response;

        $success = (bool) Arr::get($response, 'success', false);
        $normalized['success'] = $success;
        $normalized['retryable'] = (bool) Arr::get($response, 'retryable', false);
        $normalized['status_code'] = Arr::get($response, 'status_code', $success ? 200 : 0);
        $normalized['request_id'] = Arr::get($response, 'request_id', '');
        $normalized['external_id'] = Arr::get($response, 'external_id');
        $normalized['latency_ms'] = Arr::get($response, 'latency_ms');
        $normalized['attempt'] = (int) Arr::get($response, 'attempt', $this->attempts());
        $normalized['endpoint'] = Arr::get($response, 'endpoint', $endpoint);
        $normalized['method'] = Arr::get($response, 'method', strtoupper($method));

        $error = Arr::get($response, 'error');
        if (! is_array($error)) {
            $error = [];
        }

        $normalized['error'] = [
            'code' => $error['code'] ?? null,
            'message' => $error['message'] ?? Arr::get($response, 'message'),
            'details' => $error['details'] ?? null,
        ];

        if (! array_key_exists('data', $normalized)) {
            $normalized['data'] = [];
        }

        return $normalized;
    }

    private function sanitizeErrorDetails(?string $details): ?string
    {
        if (! is_string($details)) {
            return $details;
        }

        $sensitive = config('generic-platforms.policy.sensitive_headers', []);
        foreach ($sensitive as $header) {
            $pattern = '/' . preg_quote($header, '/') . '\\s*:\\s*[^\\n\\r]*/i';
            $details = preg_replace($pattern, $header . ': [redacted]', $details);
        }

        return $details;
    }

    private function maybeAddHubspotFailureNote(array $response, HubspotApiServiceRefactored $hubspotApiService): array
    {
        $sourceEventId = Arr::get($this->payload, 'source_event_id')
            ?? Arr::get($this->payload, '_event_metadata.event_id');

        $sourceEvent = is_numeric($sourceEventId)
            ? Event::query()->with('platform')->find((int) $sourceEventId)
            : null;

        if (($sourceEvent?->platform?->type ?? null) !== 'hubspot') {
            return [
                'attempted' => false,
                'reason' => 'source_platform_not_hubspot',
            ];
        }

        $contactId = $this->resolveHubspotContactId();
        if ($contactId === null) {
            return [
                'attempted' => false,
                'reason' => 'hubspot_contact_id_missing',
            ];
        }

        $this->applyHubspotRuntimeConfig($sourceEvent);

        $noteResponse = $hubspotApiService->addNoteToObject(
            'contacts',
            $contactId,
            $this->buildOperationalFailureNote($response),
            [
                'event_id' => $this->event->id,
                'record_id' => $this->record->id,
                'source_event_id' => $sourceEvent?->id,
                'status_code' => $response['status_code'] ?? null,
            ]
        );

        return [
            'attempted' => true,
            'success' => (bool) ($noteResponse['success'] ?? false),
            'contact_id' => $contactId,
            'status_code' => $noteResponse['status_code'] ?? null,
            'note_id' => Arr::get($noteResponse, 'data.id'),
            'error' => $noteResponse['error'] ?? null,
        ];
    }

    private function resolveHubspotContactId(): ?string
    {
        foreach ([
            Arr::get($this->payload, 'hubspot_object_id'),
            Arr::get($this->payload, 'hubspot_contact_id'),
            Arr::get($this->payload, 'hubspotObjectId'),
            Arr::get($this->payload, 'hs_object_id'),
            Arr::get($this->payload, 'objectId'),
            Arr::get($this->payload, 'id'),
        ] as $candidate) {
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

    private function applyHubspotRuntimeConfig(Event $sourceEvent): void
    {
        $credentials = $sourceEvent->platform?->credentials ?? [];
        $settings = $sourceEvent->platform?->settings ?? [];
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

    private function buildOperationalFailureNote(array $response): string
    {
        $field = Arr::get($response, 'data.field');
        $message = Arr::get($response, 'data.error')
            ?? Arr::get($response, 'error.message')
            ?? 'Fallo la sincronizacion del contacto en la plataforma destino.';

        return trim(implode("\n", array_filter([
            '[Integrador] Error de sincronizacion de contacto',
            'Operacion: envio a plataforma destino',
            'Evento: ' . ($this->event->name ?: $this->event->event_type_id),
            'Motivo: ' . trim((string) $message),
            is_scalar($field) && trim((string) $field) !== '' ? 'Propiedad: ' . trim((string) $field) : null,
            isset($response['status_code']) ? 'Codigo HTTP: ' . $response['status_code'] : null,
            'Record: #' . $this->record->id,
            'Fecha: ' . now()->toISOString(),
        ])));
    }

    private function acquireIdempotencyKey(
        string $endpoint,
        string $method,
        array $policy,
        EventLoggingService $eventLoggingService
    ): array
    {
        if (! (bool) ($policy['enabled'] ?? false)) {
            return [
                'skip' => false,
                'model' => null,
            ];
        }

        $template = (string) ($policy['key_template'] ?? '{event_id}:{record_id}:{method}:{endpoint}');
        $key = $this->buildIdempotencyKey($endpoint, $method, $template);
        $ttlHours = max(1, (int) ($policy['ttl_hours'] ?? 24));
        $expiresAt = now()->addHours($ttlHours);

        $state = DB::transaction(function () use ($key, $endpoint, $method, $expiresAt): array {
            $existing = EventIdempotencyKey::query()
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                $created = EventIdempotencyKey::query()->create([
                    'idempotency_key' => $key,
                    'event_id' => $this->event->id,
                    'record_id' => $this->record->id,
                    'endpoint' => $endpoint,
                    'method' => strtoupper($method),
                    'status' => 'processing',
                    'expires_at' => $expiresAt,
                ]);

                return [
                    'model' => $created,
                    'state' => 'acquired',
                ];
            }

            $isExpired = $existing->expires_at?->isPast() ?? false;

            if (! $isExpired && $existing->status === 'success') {
                return [
                    'model' => $existing,
                    'state' => 'already_processed',
                ];
            }

            if (! $isExpired && $existing->status === 'processing') {
                return [
                    'model' => $existing,
                    'state' => 'already_processing',
                ];
            }

            $existing->update([
                'event_id' => $this->event->id,
                'record_id' => $this->record->id,
                'endpoint' => $endpoint,
                'method' => strtoupper($method),
                'status' => 'processing',
                'expires_at' => $expiresAt,
            ]);

            return [
                'model' => $existing->fresh(),
                'state' => 'acquired',
            ];
        });

        if ($state['state'] === 'already_processed') {
            $eventLoggingService->logEventWarning($this->record, 'Idempotent request already processed successfully.');

            return [
                'skip' => true,
                'model' => $state['model'],
            ];
        }

        if ($state['state'] === 'already_processing') {
            $eventLoggingService->logEventWarning($this->record, 'Idempotent request already in progress.');

            return [
                'skip' => true,
                'model' => $state['model'],
            ];
        }

        return [
            'skip' => false,
            'model' => $state['model'],
        ];
    }

    private function updateIdempotencyStatus(?EventIdempotencyKey $model, string $status, array $metadata = []): void
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

    private function buildIdempotencyKey(string $endpoint, string $method, string $template): string
    {
        $path = parse_url($endpoint, PHP_URL_PATH) ?: $endpoint;

        $raw = strtr($template, [
            '{event_id}' => (string) $this->event->id,
            '{record_id}' => (string) $this->record->id,
            '{method}' => strtoupper($method),
            '{path}' => (string) $path,
            '{endpoint}' => $endpoint,
        ]);

        return 'evt:' . $this->event->id . ':idem:' . Str::lower(sha1($raw));
    }

    private function extractRetryAfter(array $response): ?int
    {
        $details = $response['error']['details'] ?? null;
        if (! $details || ! is_string($details)) {
            return null;
        }

        if (preg_match('/retry-after:\\s*(\\d+)/i', $details, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}

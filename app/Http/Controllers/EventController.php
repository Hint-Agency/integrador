<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Jobs\ExecuteEventJob;
use App\Models\Event;
use App\Services\EventFlowService;
use App\Services\EventProcessingService;
use App\Services\EventTriggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    public function __construct(
        protected EventFlowService $eventFlowService,
        protected EventProcessingService $eventProcessingService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Event::query()->with(['platform:id,name,slug,type', 'to_event:id,name', 'httpConfig']);

        if ($request->filled('platform_id')) {
            $query->where('platform_id', (int) $request->input('platform_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', (string) $request->input('type'));
        }

        if ($request->has('active')) {
            $query->where('active', filter_var($request->input('active'), FILTER_VALIDATE_BOOL));
        }

        $perPage = max(1, min((int) $request->input('per_page', 20), 100));
        $events = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (Event $event): array => $this->serializeEvent($event));

        return response()->json([
            'success' => true,
            'data' => $events,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateEventPayload($request);
        $this->assertAllowedBaseUrl(Arr::get($data, 'http_config.base_url'));

        $event = Event::query()->create($this->extractEventData($data) + [
            'active' => (bool) ($data['active'] ?? true),
        ]);
        $this->syncHttpConfig($event, Arr::get($data, 'http_config'));
        $event->load(['platform:id,name,slug,type', 'to_event:id,name', 'httpConfig']);

        return response()->json([
            'success' => true,
            'message' => 'Event created successfully.',
            'data' => $this->serializeEvent($event),
        ], 201);
    }

    public function show(Request $request, Event $event)
    {
        $event->load(['platform', 'to_event', 'httpConfig']);

        if ($request->expectsJson() || $request->is('api/*')) {
            $event->load('httpConfig');

            return response()->json([
                'success' => true,
                'data' => $this->serializeEvent($event),
            ]);
        }

        $eventType = $event->getEventTypeEnum();

        return inertia('Events/Show', [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'event_type_id' => $event->event_type_id,
                'event_type_label' => $eventType?->label(),
                'platform' => $event->platform?->name ?? $event->platform?->type,
                'platform_type' => $event->platform?->type,
                'type' => $event->type,
                'active' => (bool) $event->active,
                'to_event_id' => $event->to_event_id,
                'method_name' => $event->getMethodName() ?? $event->method_name,
                'subscription_type' => $event->subscription_type,
                'schedule_expression' => $event->schedule_expression,
                'endpoint' => $event->httpConfig
                    ? trim($event->httpConfig->method.' '.trim($event->httpConfig->path ?? '', '/'))
                    : $event->endpoint_api,
            ],
            'flow' => $this->eventFlowService->getEventFlow($event),
            'can_manage_events' => $request->user()?->hasPermission('events.manage') ?? false,
        ]);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $data = $this->validateEventPayload($request, $event);
        $this->assertAllowedBaseUrl(Arr::get($data, 'http_config.base_url'));

        $event->update($this->extractEventData($data) + [
            'active' => (bool) ($data['active'] ?? false),
        ]);
        $this->syncHttpConfig($event, Arr::get($data, 'http_config'));
        $event->load(['platform:id,name,slug,type', 'to_event:id,name', 'httpConfig']);

        return response()->json([
            'success' => true,
            'message' => 'Event updated successfully.',
            'data' => $this->serializeEvent($event),
        ]);
    }

    public function destroy(Event $event): JsonResponse
    {
        $event->delete();

        return response()->json([
            'success' => true,
            'message' => 'Event deleted successfully.',
        ]);
    }

    public function statistics(): JsonResponse
    {
        $stats = [
            'total' => Event::count(),
            'active' => Event::where('active', true)->count(),
            'webhook' => Event::where('type', 'webhook')->count(),
            'schedule' => Event::where('type', 'schedule')->count(),
            'recent' => Event::where('created_at', '>=', now()->subDays(7))->count(),
            'by_platform' => Event::with('platform')->get()->groupBy('platform.type')->map->count(),
            'by_event_type' => Event::whereNotNull('event_type_id')->get()->groupBy('event_type_id')->map->count(),
        ];

        return response()->json($stats);
    }

    public function getEventFlow(Event $event): JsonResponse
    {
        try {
            $flowInfo = $this->eventFlowService->getEventFlow($event);

            return response()->json($flowInfo);
        } catch (\Throwable $exception) {
            Log::error('Error getting event flow', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error getting event flow: '.$exception->getMessage(),
            ], 500);
        }
    }

    public function executeEventFlow(Request $request, Event $event): JsonResponse
    {
        try {
            $payload = $request->input('payload', $this->generateExamplePayload($event));
            $flow = $this->eventFlowService->getEventFlow($event);
            $rootEvent = isset($flow['root_id']) ? Event::find($flow['root_id']) : $event;

            $result = $this->eventFlowService->executeEventFlow($rootEvent ?? $event, $payload);

            return response()->json($result);
        } catch (\Throwable $exception) {
            Log::error('Error executing event flow', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error executing event flow: '.$exception->getMessage(),
            ], 500);
        }
    }

    public function testEvent(Request $request, Event $event): JsonResponse
    {
        $payload = $request->input('payload', []);
        if (empty($payload)) {
            $payload = $this->generateExamplePayload($event);
        }

        if ($event->type === 'webhook' && ! isset($payload['subscriptionType'])) {
            $payload['subscriptionType'] = $event->getSubscriptionType() ?? $event->name;
        }

        if ($event->type === 'webhook') {
            $validation = $this->validateWebhookPayload($payload, $event);
            if (! $validation['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid webhook payload structure',
                    'validation_errors' => $validation['errors'],
                ], 400);
            }
        }

        $start = microtime(true);
        $result = $this->eventProcessingService->processEvent(
            $payload['subscriptionType'] ?? $event->getSubscriptionType() ?? $event->name,
            $payload,
            $event->platform
        );
        $executionTime = microtime(true) - $start;

        $result['test_info'] = [
            'event_id' => $event->id,
            'event_name' => $event->name,
            'platform' => $event->platform?->type,
            'subscription_type' => $event->getSubscriptionType() ?? $event->name,
            'tested_at' => now()->toISOString(),
            'execution_time_ms' => round($executionTime * 1000, 2),
            'payload_used' => $payload,
        ];

        return response()->json($result);
    }

    public function executeNow(Request $request, Event $event): JsonResponse
    {
        if ($event->type !== 'schedule') {
            return response()->json([
                'success' => false,
                'message' => 'Only schedule events can be executed immediately.',
            ], 400);
        }

        if (! $event->active) {
            return response()->json([
                'success' => false,
                'message' => 'Event must be active to be executed.',
            ], 400);
        }

        ExecuteEventJob::dispatch($event)->onQueue('events');

        return response()->json([
            'success' => true,
            'message' => 'Event enqueued for execution successfully.',
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'type' => $event->type,
            ],
        ]);
    }

    public function getTriggers(Event $event, EventTriggerService $eventTriggerService): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $eventTriggerService->getEventTriggers($event),
        ]);
    }

    public function updateTriggers(Request $request, Event $event, EventTriggerService $eventTriggerService): JsonResponse
    {
        $data = $request->validate([
            'groups' => ['required', 'array'],
            'groups.*.id' => ['nullable', 'integer', 'exists:event_trigger_groups,id'],
            'groups.*.name' => ['required', 'string', 'max:255'],
            'groups.*.operator' => ['required', Rule::in(['and', 'or'])],
            'groups.*.active' => ['sometimes', 'boolean'],
            'groups.*.conditions' => ['required', 'array', 'min:1'],
            'groups.*.conditions.*.id' => ['nullable', 'integer', 'exists:event_trigger_group_conditions,id'],
            'groups.*.conditions.*.field' => ['required', 'string', 'max:255'],
            'groups.*.conditions.*.operator' => ['required', Rule::in(EventTriggerService::SUPPORTED_OPERATORS)],
            'groups.*.conditions.*.value' => ['nullable'],
        ]);

        $updated = $eventTriggerService->syncEventTriggers($event, $data['groups']);

        return response()->json([
            'success' => true,
            'message' => 'Event triggers updated successfully.',
            'data' => $updated,
        ]);
    }

    private function generateExamplePayload(Event $event): array
    {
        return [
            'subscriptionType' => $event->getSubscriptionType() ?? $event->name,
            'timestamp' => now()->timestamp,
            'payload' => [
                'event_id' => $event->id,
                'event_name' => $event->name,
            ],
        ];
    }

    private function validateWebhookPayload(array $payload, Event $event): array
    {
        $errors = [];
        if (! isset($payload['subscriptionType'])) {
            $errors[] = 'Missing required field: subscriptionType';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    private function validateEventPayload(Request $request, ?Event $event = null): array
    {
        $eventId = $event?->id;

        return $request->validate([
            'platform_id' => ['required', 'integer', 'exists:platforms,id'],
            'to_event_id' => ['nullable', 'integer', 'exists:events,id', Rule::notIn([$eventId])],
            'name' => ['required', 'string', 'max:255'],
            'event_type_id' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['webhook', 'schedule'])],
            'subscription_type' => ['nullable', 'string', 'max:255'],
            'method_name' => ['nullable', 'string', 'max:255'],
            'endpoint_api' => ['nullable', 'string', 'max:255'],
            'schedule_expression' => ['nullable', 'required_if:type,schedule', 'string', 'max:255'],
            'command_sql' => ['nullable', 'string'],
            'enable_update_hubdb' => ['sometimes', 'boolean'],
            'hubdb_table_id' => ['nullable', 'integer', 'required_if:enable_update_hubdb,1'],
            'active' => ['sometimes', 'boolean'],
            'payload_mapping' => ['sometimes', 'array'],
            'meta' => ['sometimes', 'array'],
            'http_config' => ['nullable', 'array'],
            'http_config.active' => ['sometimes', 'boolean'],
            'http_config.method' => ['nullable', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])],
            'http_config.base_url' => ['nullable', 'url', 'max:255'],
            'http_config.path' => ['nullable', 'string', 'max:255'],
            'http_config.headers_json' => ['nullable', 'array'],
            'http_config.query_json' => ['nullable', 'array'],
            'http_config.auth_mode' => ['nullable', Rule::in(['bearer_api_key', 'basic_auth', 'oauth2_client_credentials'])],
            'http_config.auth_config_json' => ['nullable', 'array'],
            'http_config.timeout_seconds' => ['nullable', 'integer', 'between:1,120'],
            'http_config.retry_policy_json' => ['nullable', 'array'],
            'http_config.idempotency_config_json' => ['nullable', 'array'],
            'http_config.allowlist_domains_json' => ['nullable', 'array'],
        ]);
    }

    private function extractEventData(array $data): array
    {
        return Arr::except($data, ['http_config']);
    }

    private function syncHttpConfig(Event $event, mixed $httpConfig): void
    {
        if (! is_array($httpConfig) || ! $this->hasHttpConfigData($httpConfig)) {
            $event->httpConfig()->delete();

            return;
        }

        $event->httpConfig()->updateOrCreate(
            ['event_id' => $event->id],
            [
                'method' => strtoupper((string) ($httpConfig['method'] ?? 'POST')),
                'base_url' => $httpConfig['base_url'] ?? null,
                'path' => $httpConfig['path'] ?? null,
                'headers_json' => $httpConfig['headers_json'] ?? [],
                'query_json' => $httpConfig['query_json'] ?? [],
                'auth_mode' => $httpConfig['auth_mode'] ?? null,
                'auth_config_json' => $httpConfig['auth_config_json'] ?? [],
                'timeout_seconds' => (int) ($httpConfig['timeout_seconds'] ?? 30),
                'retry_policy_json' => $httpConfig['retry_policy_json'] ?? [],
                'idempotency_config_json' => $httpConfig['idempotency_config_json'] ?? [],
                'allowlist_domains_json' => $httpConfig['allowlist_domains_json'] ?? [],
                'active' => (bool) ($httpConfig['active'] ?? true),
            ]
        );
    }

    private function hasHttpConfigData(array $httpConfig): bool
    {
        foreach ([
            'base_url',
            'path',
            'headers_json',
            'query_json',
            'auth_mode',
            'auth_config_json',
            'retry_policy_json',
            'idempotency_config_json',
            'allowlist_domains_json',
        ] as $key) {
            $value = $httpConfig[$key] ?? null;
            if (is_array($value) && ! empty($value)) {
                return true;
            }
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function assertAllowedBaseUrl(?string $baseUrl): void
    {
        if (! $baseUrl) {
            return;
        }

        $allowlist = config('generic-platforms.policy.allowed_domains', []);
        if (empty($allowlist)) {
            return;
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (! $host) {
            throw ValidationException::withMessages([
                'http_config.base_url' => 'Base URL is invalid.',
            ]);
        }

        foreach ($allowlist as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain === '') {
                continue;
            }

            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'http_config.base_url' => 'Domain is not allowed by configured allowlist.',
        ]);
    }

    private function serializeEvent(Event $event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->name,
            'event_type_id' => $event->event_type_id,
            'event_type_label' => EventType::tryFrom((string) $event->event_type_id)?->label(),
            'type' => $event->type,
            'subscription_type' => $event->subscription_type,
            'method_name' => $event->method_name,
            'endpoint_api' => $event->endpoint_api,
            'schedule_expression' => $event->schedule_expression,
            'last_executed_at' => optional($event->last_executed_at)?->toISOString(),
            'command_sql' => $event->command_sql,
            'enable_update_hubdb' => (bool) $event->enable_update_hubdb,
            'hubdb_table_id' => $event->hubdb_table_id,
            'active' => (bool) $event->active,
            'payload_mapping' => $event->payload_mapping ?? [],
            'meta' => $event->meta ?? [],
            'platform' => $event->platform ? [
                'id' => $event->platform->id,
                'name' => $event->platform->name,
                'slug' => $event->platform->slug,
                'type' => $event->platform->type,
            ] : null,
            'to_event' => $event->to_event ? [
                'id' => $event->to_event->id,
                'name' => $event->to_event->name,
            ] : null,
            'http_config' => $event->httpConfig ? [
                'id' => $event->httpConfig->id,
                'method' => $event->httpConfig->method,
                'base_url' => $event->httpConfig->base_url,
                'path' => $event->httpConfig->path,
                'headers_json' => $event->httpConfig->headers_json ?? [],
                'query_json' => $event->httpConfig->query_json ?? [],
                'auth_mode' => $event->httpConfig->auth_mode,
                'auth_config_json' => $event->httpConfig->auth_config_json ?? [],
                'timeout_seconds' => $event->httpConfig->timeout_seconds,
                'retry_policy_json' => $event->httpConfig->retry_policy_json ?? [],
                'idempotency_config_json' => $event->httpConfig->idempotency_config_json ?? [],
                'allowlist_domains_json' => $event->httpConfig->allowlist_domains_json ?? [],
                'active' => (bool) $event->httpConfig->active,
            ] : null,
        ];
    }
}

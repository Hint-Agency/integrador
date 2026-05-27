<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Record;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EventFlowService
{
    public function __construct(
        protected EventLoggingService $eventLoggingService,
        protected EventProcessingService $eventProcessingService
    ) {}

    /**
     * Ejecuta un flujo completo de eventos.
     */
    public function executeEventFlow(Event $rootEvent, array $initialData, ?Record $parentRecord = null): array
    {
        try {
            DB::beginTransaction();

            $rootEvent = $this->getRootEvent($rootEvent);

            $flowContext = [
                'initial_data' => $initialData,
                'executed_events' => [],
                'current_data' => $initialData,
                'errors' => [],
            ];

            $result = $this->executeEventInFlow($rootEvent, $flowContext, $parentRecord);
            if (! $result['success']) {
                DB::rollBack();

                return $result;
            }

            $visited = [$rootEvent->id];
            $this->executeChildEvents($rootEvent, $flowContext, $result['record'], $visited);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Event flow executed successfully',
                'executed_events' => $flowContext['executed_events'],
                'final_data' => $flowContext['current_data'],
                'errors' => $flowContext['errors'],
            ];
        } catch (\Throwable $exception) {
            DB::rollBack();

            Log::error('Event flow execution failed', [
                'root_event_id' => $rootEvent->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Event flow execution failed: '.$exception->getMessage(),
                'executed_events' => [],
                'final_data' => $initialData,
                'errors' => [$exception->getMessage()],
            ];
        }
    }

    /**
     * Devuelve la estructura de flujo para visualizacion (solo lectura).
     */
    public function getEventFlow(Event $event): array
    {
        $root = $this->getRootEvent($event);

        $nodes = [];
        $edges = [];
        $visited = [];
        $this->collectFlow($root, null, 0, $nodes, $edges, $visited);

        return [
            'root_id' => $root->id,
            'chain' => $this->getEventChainIds($event),
            'nodes' => array_values($nodes),
            'edges' => $edges,
        ];
    }

    /**
     * Aplica transformaciones de mapeo para un evento individual sin ejecutar su metodo de negocio.
     */
    public function transformPayloadForEvent(Event $event, array $data): array
    {
        return $this->transformDataForEvent($event, $data);
    }

    private function executeEventInFlow(Event $event, array &$flowContext, ?Record $parentRecord = null): array
    {
        $record = $this->eventLoggingService->createEventRecord(
            $event->event_type_id ?? $event->name,
            'init',
            $flowContext['current_data'],
            'Event flow execution started',
            $parentRecord?->id,
            $event->id
        );

        $transformedData = $this->transformDataForEvent($event, $flowContext['current_data']);

        $validation = $this->validateEventData($event, $transformedData);
        if (! $validation['valid']) {
            $this->eventLoggingService->logEventError($record, new \Exception($validation['message']));
            $flowContext['errors'][] = $validation['message'];

            return [
                'success' => false,
                'message' => $validation['message'],
                'record' => $record,
            ];
        }

        $serviceClass = $this->eventProcessingService->getServiceClass($event->platform);
        if (! $serviceClass || ! class_exists($serviceClass)) {
            $message = 'Service class not found for platform.';
            $this->eventLoggingService->logEventWarning($record, $message, [
                'reason' => 'service_class_not_found',
                'event_id' => $event->id,
                'event_type_id' => $event->event_type_id,
                'platform_type' => $event->platform?->type,
                'service_class' => $serviceClass,
            ]);
            $flowContext['errors'][] = $message;

            return [
                'success' => false,
                'message' => $message,
                'record' => $record,
            ];
        }

        $service = app()->make($serviceClass, [
            'platform' => $event->platform,
            'event' => $event,
            'record' => $record,
        ]);
        $methodName = $event->getMethodName() ?? $event->method_name;

        if (! $methodName || ! method_exists($service, $methodName)) {
            $message = 'Event method not available for execution.';
            $this->eventLoggingService->logEventWarning($record, $message, [
                'reason' => 'method_not_available',
                'event_id' => $event->id,
                'event_type_id' => $event->event_type_id,
                'platform_type' => $event->platform?->type,
                'service_class' => $serviceClass,
                'method_name' => $methodName,
                'subscription_type' => $event->getSubscriptionType(),
            ]);
            $flowContext['errors'][] = $message;

            return [
                'success' => false,
                'message' => $message,
                'record' => $record,
            ];
        }

        $result = $this->invokeServiceMethod($service, $methodName, $transformedData, $event, $record);

        if (! Arr::get($result, 'success', false)) {
            $message = Arr::get($result, 'message', 'Event execution failed.');
            $this->eventLoggingService->logEventError($record, new \Exception($message));
            $flowContext['errors'][] = $message;

            return [
                'success' => false,
                'message' => $message,
                'record' => $record,
            ];
        }

        $this->eventLoggingService->logEventSuccess($record, Arr::get($result, 'message', 'Event executed.'));

        $flowContext['executed_events'][] = [
            'event_id' => $event->id,
            'event_name' => $event->name,
            'status' => 'success',
            'record_id' => $record->id,
            'executed_at' => now()->toISOString(),
        ];

        $flowContext['current_data'] = $this->enrichDataWithEventResult(
            $transformedData,
            Arr::get($result, 'data', [])
        );

        return [
            'success' => true,
            'message' => Arr::get($result, 'message', 'Event executed.'),
            'record' => $record,
        ];
    }

    private function executeChildEvents(Event $parentEvent, array &$flowContext, Record $parentRecord, array &$visited): void
    {
        $nextEvent = $parentEvent->to_event;
        if (! $nextEvent || ! $nextEvent->active) {
            return;
        }

        if (in_array($nextEvent->id, $visited, true)) {
            Log::warning('Circular event chain detected while executing flow', [
                'parent_event_id' => $parentEvent->id,
                'next_event_id' => $nextEvent->id,
            ]);

            return;
        }

        $visited[] = $nextEvent->id;

        $result = $this->executeEventInFlow($nextEvent, $flowContext, $parentRecord);
        if ($result['success']) {
            $this->executeChildEvents($nextEvent, $flowContext, $result['record'], $visited);
        } else {
            Log::warning('Child event failed during flow execution', [
                'parent_event_id' => $parentEvent->id,
                'child_event_id' => $nextEvent->id,
                'message' => $result['message'],
            ]);
        }
    }

    private function transformDataForEvent(Event $event, array $data): array
    {
        if (array_is_list($data) && $this->isListOfArrays($data)) {
            return array_map(
                fn (array $item): array => $this->transformDataItemForEvent($event, $item),
                $data
            );
        }

        $transformed = $this->transformDataItemForEvent($event, $data);

        $transformed['_event_metadata'] = [
            'event_id' => $event->id,
            'event_name' => $event->name,
            'platform' => $event->platform?->type,
            'transformed_at' => now()->toISOString(),
        ];

        return $transformed;
    }

    private function transformDataItemForEvent(Event $event, array $data): array
    {
        $transformed = $data;

        $relationships = $event->propertyRelationships()->with(['property', 'relatedProperty'])->get();
        foreach ($relationships as $relationship) {
            if (! $relationship->active) {
                continue;
            }

            $sourceKey = $relationship->mapping_key
                ?: ($relationship->property?->key ?: $relationship->property?->name);
            $targetKey = $relationship->relatedProperty?->key ?: $relationship->relatedProperty?->name;

            if (! $sourceKey || ! $targetKey) {
                continue;
            }

            $value = data_get($data, $sourceKey);
            if ($value === null) {
                continue;
            }

            $catalogValue = $this->resolveCatalogMappingValue($relationship->meta, $event, $value);
            if (($catalogValue['matched'] ?? false) === false) {
                Arr::forget($transformed, $targetKey);

                continue;
            }

            $targetType = $relationship->relatedProperty?->type;
            $transformedValue = $this->castValue($catalogValue['value'], $targetType);

            if ($targetType === 'file') {
                $transformedValue = $this->resolveFilePayload($value);
            }

            data_set($transformed, $targetKey, $transformedValue);
        }

        return $transformed;
    }

    private function resolveCatalogMappingValue(?array $meta, Event $event, mixed $value): array
    {
        $catalogConfig = $meta['catalog'] ?? null;
        if (! is_array($catalogConfig)) {
            return [
                'matched' => true,
                'value' => $value,
            ];
        }

        $catalogPath = $catalogConfig['path'] ?? null;
        if (! is_string($catalogPath) || trim($catalogPath) === '') {
            return [
                'matched' => true,
                'value' => $value,
            ];
        }

        $platform = match ($catalogConfig['platform'] ?? 'source') {
            'target', 'event' => $event->to_event?->platform ?? $event->platform,
            default => $event->platform,
        };

        $catalog = data_get($platform?->settings ?? [], trim($catalogPath));
        if (! is_array($catalog)) {
            return [
                'matched' => false,
                'value' => null,
            ];
        }

        return $this->catalogLookup($catalog, $value, (string) ($catalogConfig['match'] ?? 'value'), (string) ($catalogConfig['output'] ?? 'key'));
    }

    private function catalogLookup(array $catalog, mixed $value, string $match, string $output): array
    {
        foreach ($catalog as $key => $catalogValue) {
            $candidate = $match === 'key' ? $key : $catalogValue;
            if (! $this->catalogValuesAreEqual($candidate, $value)) {
                continue;
            }

            return [
                'matched' => true,
                'value' => $output === 'value' ? $catalogValue : $key,
            ];
        }

        return [
            'matched' => false,
            'value' => null,
        ];
    }

    private function catalogValuesAreEqual(mixed $left, mixed $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return (string) (int) $left === (string) (int) $right;
        }

        if (! is_scalar($left) || ! is_scalar($right)) {
            return false;
        }

        return mb_strtolower(trim((string) $left)) === mb_strtolower(trim((string) $right));
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private function isListOfArrays(array $data): bool
    {
        foreach ($data as $item) {
            if (! is_array($item)) {
                return false;
            }
        }

        return ! empty($data);
    }

    private function castValue(mixed $value, ?string $type): mixed
    {
        if (! $type) {
            return $value;
        }

        return match ($type) {
            'boolean', 'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            'integer', 'int' => is_numeric($value) ? (int) $value : 0,
            'float', 'decimal' => is_numeric($value) ? (float) $value : 0.0,
            'array' => is_array($value) ? $value : [$value],
            'string' => is_scalar($value) ? (string) $value : json_encode($value),
            default => $value,
        };
    }

    private function validateEventData(Event $event, array $data): array
    {
        $requiredProperties = $event->properties()->where('required', true)->get();
        $missingFields = [];

        foreach ($requiredProperties as $property) {
            $key = $property->key ?: $property->name;
            $value = data_get($data, $key);
            if ($value === null || $value === '') {
                $missingFields[] = $key;
            }
        }

        if (! empty($missingFields)) {
            return [
                'valid' => false,
                'message' => 'Missing required fields: '.implode(', ', $missingFields),
            ];
        }

        return [
            'valid' => true,
            'message' => 'Validation passed',
        ];
    }

    private function enrichDataWithEventResult(array $data, array $resultData): array
    {
        if (empty($resultData)) {
            return $data;
        }

        return array_merge($data, $resultData);
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
            'message' => $result ? 'Event executed.' : 'Event execution failed.',
            'data' => [],
        ];
    }

    private function resolveFilePayload(mixed $value): array
    {
        if (is_array($value) && (isset($value['content']) || isset($value['url']) || isset($value['download_url']))) {
            return $value;
        }

        if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
            try {
                $response = Http::timeout(30)->get($value);
                if ($response->ok()) {
                    return [
                        'filename' => basename(parse_url($value, PHP_URL_PATH) ?: 'file'),
                        'content_type' => $response->header('Content-Type'),
                        'content' => base64_encode($response->body()),
                        'source_url' => $value,
                    ];
                }
            } catch (\Throwable $exception) {
                Log::warning('Unable to download file property', [
                    'url' => $value,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'source' => $value,
        ];
    }

    private function getRootEvent(Event $event): Event
    {
        $current = $event;
        $visited = [];

        while (true) {
            if (in_array($current->id, $visited, true)) {
                break;
            }

            $visited[] = $current->id;
            $parent = Event::query()
                ->where('to_event_id', $current->id)
                ->first();

            if (! $parent) {
                break;
            }

            $current = $parent;
        }

        return $current->loadMissing('platform', 'to_event');
    }

    private function getEventChainIds(Event $event): array
    {
        $chain = [];
        $current = $this->getRootEvent($event);
        $visited = [];

        while ($current) {
            if (in_array($current->id, $visited, true)) {
                break;
            }
            $visited[] = $current->id;
            $chain[] = $current->id;
            $current = $current->to_event;
        }

        return $chain;
    }

    /**
     * Recolecta nodos y aristas del flujo de eventos.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, int|null>>  $edges
     */
    private function collectFlow(Event $event, ?int $parentId, int $depth, array &$nodes, array &$edges, array &$visited): void
    {
        if (in_array($event->id, $visited, true)) {
            return;
        }

        $visited[] = $event->id;
        $event->loadMissing(['platform', 'to_event']);

        $nodes[$event->id] = [
            'id' => $event->id,
            'name' => $event->name,
            'event_type_id' => $event->event_type_id,
            'platform' => $event->platform?->name ?? $event->platform?->type,
            'platform_type' => $event->platform?->type,
            'type' => $event->type,
            'active' => (bool) $event->active,
            'to_event_id' => $event->to_event_id,
            'depth' => $depth,
        ];

        if ($parentId) {
            $edges[] = [
                'from' => $parentId,
                'to' => $event->id,
            ];
        }

        if ($event->to_event) {
            $this->collectFlow($event->to_event, $event->id, $depth + 1, $nodes, $edges, $visited);
        }
    }
}

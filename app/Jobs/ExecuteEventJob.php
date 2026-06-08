<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Record;
use App\Services\EventLoggingService;
use App\Services\EventProcessingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessNextEventJob;

class ExecuteEventJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 300;
    public int $uniqueFor = 3600;

    public function __construct(
        public Event $event
    ) {
        $this->onQueue('events');
    }

    public function uniqueId(): string
    {
        return 'event:'.$this->event->id;
    }

    public function handle(EventLoggingService $eventLoggingService, EventProcessingService $eventProcessingService): void
    {
        $methodName = $this->event->getMethodName() ?? $this->event->method_name;
        if (! $methodName) {
            $eventLoggingService->createEventRecord(
                $this->event->name,
                'error',
                $this->event->toArray(),
                'Invalid method name for scheduled event.',
                null,
                $this->event->id,
                [
                    'reason' => 'missing_method_name',
                    'event_id' => $this->event->id,
                    'event_type_id' => $this->event->event_type_id,
                    'platform_id' => $this->event->platform_id,
                    'queue' => 'events',
                ]
            );
            return;
        }

        $record = $eventLoggingService->createEventRecord(
            $this->event->name,
            'init',
            $this->event->toArray(),
            'Scheduled event execution initiated',
            null,
            $this->event->id
        );

        $serviceClass = $eventProcessingService->getServiceClass($this->event->platform);
        if (! $serviceClass || ! class_exists($serviceClass)) {
            $eventLoggingService->logEventWarning($record, 'Service class not found for scheduled event.', [
                'reason' => 'service_class_not_found',
                'event_id' => $this->event->id,
                'event_type_id' => $this->event->event_type_id,
                'platform_type' => $this->event->platform?->type,
                'service_class' => $serviceClass,
                'queue' => 'events',
            ]);
            return;
        }

        $payload = $this->buildSchedulePayload();

        try {
            $service = app()->make($serviceClass, [
                'platform' => $this->event->platform,
                'event' => $this->event,
                'record' => $record,
            ]);
            $result = $this->invokeServiceMethod($service, $methodName, $payload, $record);
            $outputPayload = $this->resolveOutputPayload($result, $payload);
            $this->mergeRecordDetails($record, [
                'service_output' => Arr::get($result, 'data', []),
                'service_message' => Arr::get($result, 'message'),
                'output_payload' => $outputPayload,
            ]);

            if (Arr::get($result, 'status') === 'warning') {
                $eventLoggingService->logEventWarning(
                    $record,
                    Arr::get($result, 'message', 'Scheduled event finished with warnings.'),
                    Arr::get($result, 'data', [])
                );
                $this->event->update(['last_executed_at' => now()]);
                return;
            }

            if (! Arr::get($result, 'success', false)) {
                $eventLoggingService->logEventError($record, new \Exception(Arr::get($result, 'message', 'Scheduled event failed')));
                return;
            }

            $eventLoggingService->logEventSuccess($record, Arr::get($result, 'message', 'Scheduled event executed.'));

            if ($this->event->to_event && $this->shouldDispatchNextPayload($outputPayload)) {
                ProcessNextEventJob::dispatch($this->event, $record, $outputPayload)->onQueue('events');
            }

            $this->event->update(['last_executed_at' => now()]);
        } catch (\Throwable $exception) {
            Log::error('Scheduled event execution failed', [
                'event_id' => $this->event->id,
                'error' => $exception->getMessage(),
            ]);
            $eventLoggingService->logEventError($record, $exception);
            throw $exception;
        }
    }

    private function invokeServiceMethod(object $service, string $methodName, array $payload, Record $record): array
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
            $subscriptionType = $this->event->getSubscriptionType() ?? $this->event->name;
            $result = $service->{$methodName}($subscriptionType, $payload, $record);
        }

        if (is_array($result)) {
            return $result;
        }

        return [
            'success' => (bool) $result,
            'message' => $result ? 'Scheduled event executed.' : 'Scheduled event failed.',
        ];
    }

    private function buildSchedulePayload(): array
    {
        $payload = [
            'scheduled_at' => now()->toISOString(),
            'event_id' => $this->event->id,
            'event_name' => $this->event->name,
            'platform' => $this->event->platform?->type,
            'schedule_expression' => $this->event->schedule_expression,
        ];

        if ($this->event->command_sql) {
            $payload['command_sql'] = $this->event->command_sql;
        }

        if ($this->event->enable_update_hubdb) {
            $payload['hubdb'] = [
                'enabled' => true,
                'table_id' => $this->event->hubdb_table_id,
            ];
        }

        return $payload;
    }

    private function mergeRecordDetails(Record $record, array $details): void
    {
        $existingDetails = is_array($record->details) ? $record->details : [];

        $record->update([
            'details' => array_replace_recursive($existingDetails, $details),
        ]);
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
}

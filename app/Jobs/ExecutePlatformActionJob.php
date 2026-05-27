<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Record;
use App\Services\EventLoggingService;
use App\Services\EventProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class ExecutePlatformActionJob implements ShouldQueue
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

    public function handle(EventProcessingService $eventProcessingService, EventLoggingService $eventLoggingService): void
    {
        $serviceClass = $eventProcessingService->getServiceClass($this->event->platform);
        $methodName = $this->event->getMethodName() ?? $this->event->method_name;

        if (! $serviceClass || ! class_exists($serviceClass) || ! $methodName) {
            $eventLoggingService->logEventWarning($this->record, 'Platform action cannot be resolved.', [
                'reason' => 'action_not_resolved',
                'service_class' => $serviceClass,
                'method_name' => $methodName,
            ]);

            return;
        }

        $service = app()->make($serviceClass, [
            'platform' => $this->event->platform,
            'event' => $this->event,
            'record' => $this->record,
        ]);

        if (! method_exists($service, $methodName)) {
            $eventLoggingService->logEventWarning($this->record, 'Platform action method is unavailable.', [
                'reason' => 'method_not_available',
                'service_class' => $serviceClass,
                'method_name' => $methodName,
            ]);

            return;
        }

        $result = $service->{$methodName}($this->payload);
        $details = [
            'job' => self::class,
            'service_class' => $serviceClass,
            'method_name' => $methodName,
            'service_output' => Arr::get($result, 'data', []),
        ];

        if (! ($result['success'] ?? false)) {
            $eventLoggingService->logEventWarning($this->record, $result['message'] ?? 'Platform action failed.', $details);

            return;
        }

        $eventLoggingService->logEventSuccess($this->record, $result['message'] ?? 'Platform action executed.');
        $this->record->update([
            'details' => [
                ...((array) $this->record->details),
                ...$details,
            ],
        ]);
    }
}

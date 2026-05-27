<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Record;
use App\Services\EventLoggingService;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class WriteBackStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 180;

    public function __construct(
        public array $payload,
        public Event $event,
        public Record $record
    ) {
        $this->onQueue('update');
    }

    public function handle(EventLoggingService $eventLoggingService, HubspotApiServiceRefactored $hubspotApi): void
    {
        $objectType = (string) Arr::get($this->payload, 'object_type', Arr::get($this->event->meta, 'writeback.object_type', 'deals'));
        $objectId = (string) Arr::get($this->payload, 'object_id', Arr::get($this->payload, 'hubspot_object_id', ''));
        $properties = (array) Arr::get($this->payload, 'properties', []);

        if ($objectId === '' || $properties === []) {
            $eventLoggingService->logEventWarning($this->record, 'Write-back skipped because object id or properties are missing.', [
                'reason' => 'missing_writeback_target',
                'object_type' => $objectType,
                'object_id_present' => $objectId !== '',
                'properties_present' => $properties !== [],
            ]);

            return;
        }

        $response = $hubspotApi->updateObject($objectType, $objectId, $properties);
        if (! ($response['success'] ?? false)) {
            $eventLoggingService->logEventWarning($this->record, 'Write-back failed.', [
                'object_type' => $objectType,
                'object_id' => $objectId,
                'properties' => $properties,
                'response' => [
                    'status_code' => $response['status_code'] ?? null,
                    'error' => $response['error'] ?? null,
                    'message' => $response['message'] ?? null,
                ],
            ]);

            return;
        }

        $eventLoggingService->logEventSuccess($this->record, 'Write-back completed.');
        $this->record->update([
            'details' => [
                ...((array) $this->record->details),
                'object_type' => $objectType,
                'object_id' => $objectId,
                'properties' => $properties,
                'response_status_code' => $response['status_code'] ?? null,
            ],
        ]);
    }
}

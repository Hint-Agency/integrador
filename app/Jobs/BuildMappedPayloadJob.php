<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Record;
use App\Services\EventFlowService;
use App\Services\EventLoggingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BuildMappedPayloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 180;

    public function __construct(
        public array $payload,
        public Event $event,
        public Record $record
    ) {
        $this->onQueue('mapping');
    }

    public function handle(EventFlowService $eventFlowService, EventLoggingService $eventLoggingService): void
    {
        $mapped = $eventFlowService->transformPayloadForEvent($this->event, $this->payload);

        $eventLoggingService->logEventSuccess($this->record, 'Mapped payload built.');
        $this->record->update([
            'details' => [
                ...((array) $this->record->details),
                'job' => self::class,
                'mapped_payload' => $mapped,
            ],
        ]);
    }
}

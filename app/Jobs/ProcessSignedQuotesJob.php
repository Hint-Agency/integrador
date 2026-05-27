<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Record;
use App\Services\EventLoggingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSignedQuotesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $backoff = 300;

    public int $timeout = 900;

    public function __construct(
        public array $quotes,
        public Event $event,
        public Record $record
    ) {
        $this->onQueue('signed-quotes');
    }

    public function handle(EventLoggingService $eventLoggingService): void
    {
        $this->record->update([
            'status' => 'processing',
            'message' => 'Processing signed quotes',
        ]);

        $canonicalRecord = $eventLoggingService->createEventRecord(
            $this->event->event_type_id ?? 'signed_quotes',
            'init',
            ['quotes' => $this->quotes],
            'Building canonical signed quotes payload',
            $this->record->id,
            $this->event->id
        );

        BuildCanonicalPayloadJob::dispatch(['quotes' => $this->quotes], $this->event, $canonicalRecord)
            ->onQueue('signed-quotes');

        $this->record->update([
            'status' => 'success',
            'message' => sprintf('Signed quotes dispatched for canonical payload build (%d)', count($this->quotes)),
            'details' => [
                'quotes_total' => count($this->quotes),
                'next_job' => BuildCanonicalPayloadJob::class,
            ],
        ]);
    }
}

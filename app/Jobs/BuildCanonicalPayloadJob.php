<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Record;
use App\Services\EventLoggingService;
use App\Services\SignedQuotesPipelineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class BuildCanonicalPayloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 300;

    public function __construct(
        public array $payload,
        public Event $event,
        public Record $record
    ) {
        $this->onQueue('signed-quotes');
    }

    public function handle(EventLoggingService $eventLoggingService, SignedQuotesPipelineService $pipelineService): void
    {
        $this->record->update([
            'status' => 'processing',
            'message' => 'Building canonical signed quotes payload',
        ]);

        $quotes = $pipelineService->normalizeQuotes($this->payload['quotes'] ?? []);
        $alreadySyncedQuotes = [];
        $pendingQuotes = [];

        foreach ($quotes as $quote) {
            if ($this->quoteAlreadySyncedToOdoo($quote)) {
                $alreadySyncedQuotes[] = $quote;

                continue;
            }

            $pendingQuotes[] = $quote;
        }

        foreach ($alreadySyncedQuotes as $quote) {
            $eventLoggingService->createEventRecord(
                $this->event->event_type_id ?? 'signed_quotes',
                'success',
                ['quote' => $quote],
                'Quote already synced to Odoo',
                $this->record->id,
                $this->event->id,
                [
                    'reason' => 'already_synced_from_hubspot_marker',
                    'hubspot_quote_id' => Arr::get($quote, 'hubspot_quote_id'),
                    'quote_id' => Arr::get($quote, 'quote_id'),
                    'odoo_id' => Arr::get($quote, 'odoo_id'),
                    'sync_status_odoo' => Arr::get($quote, 'sync_status_odoo'),
                ]
            );
        }

        if ($pendingQuotes === []) {
            $this->record->update([
                'status' => 'success',
                'message' => 'No pending signed quotes to process',
                'details' => [
                    'quotes_total' => count($quotes),
                    'already_synced_count' => count($alreadySyncedQuotes),
                    'pending_count' => 0,
                ],
            ]);

            return;
        }

        $canonicalPayload = [
            'quotes' => $pendingQuotes,
            'source_event_id' => $this->event->id,
            'source_platform' => $this->event->platform?->type,
        ];

        $validationRecord = $eventLoggingService->createEventRecord(
            $this->event->event_type_id ?? 'signed_quotes',
            'init',
            $canonicalPayload,
            'Validating canonical signed quote entities',
            $this->record->id,
            $this->event->id,
            [
                'job' => self::class,
                'quotes_total' => count($quotes),
                'already_synced_count' => count($alreadySyncedQuotes),
                'pending_count' => count($pendingQuotes),
            ]
        );

        ValidateEntitiesJob::dispatch($canonicalPayload, $this->event, $validationRecord)
            ->onQueue('validation');

        $this->record->update([
            'status' => 'success',
            'message' => 'Canonical signed quotes payload built',
            'details' => [
                'quotes_total' => count($quotes),
                'already_synced_count' => count($alreadySyncedQuotes),
                'pending_count' => count($pendingQuotes),
                'next_job' => ValidateEntitiesJob::class,
            ],
        ]);
    }

    private function quoteAlreadySyncedToOdoo(array $quote): bool
    {
        $status = strtolower(trim((string) Arr::get($quote, 'sync_status_odoo', '')));
        $odooId = Arr::get($quote, 'odoo_id');

        return in_array($status, ['success', 'already_exists'], true)
            && is_scalar($odooId)
            && trim((string) $odooId) !== '';
    }
}

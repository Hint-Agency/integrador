<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Record;
use App\Services\EventLoggingService;
use App\Services\EventProcessingService;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use App\Services\SignedQuotesPipelineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class CreateQuoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $backoff = 300;

    public int $timeout = 900;

    public function __construct(
        public array $payload,
        public Event $event,
        public Record $record
    ) {
        $this->onQueue('signed-quotes');
    }

    public function handle(
        EventLoggingService $eventLoggingService,
        HubspotApiServiceRefactored $hubspotApi,
        SignedQuotesPipelineService $pipelineService,
        ?EventProcessingService $eventProcessingService = null
    ): void {
        $this->record->update([
            'status' => 'processing',
            'message' => 'Creating quote in target platform',
        ]);

        $targetPlatform = (string) Arr::get($this->payload, 'target_platform', 'odoo');
        $createdQuotes = [];
        $sourcePlatformUpdates = [];

        foreach (Arr::get($this->payload, 'quotes', []) as $quote) {
            $executionResponse = [
                'quote_id' => Arr::get($quote, 'quote_id'),
                'hubspot_quote_id' => Arr::get($quote, 'hubspot_quote_id'),
                'target_platform' => $targetPlatform,
                'entity_results' => Arr::get($quote, 'entity_results', []),
                'hubspot_sync_metadata' => Arr::get($quote, 'hubspot_sync_metadata', []),
                'raw' => Arr::get($quote, 'raw', []),
                'resolved_associations' => Arr::get($quote, 'resolved_associations', []),
                'status' => 'pending_destination_creation',
                'sync_status' => 'processing',
            ];

            $createdQuotes[] = $executionResponse;
        }

        $details = [
            'target_platform' => $targetPlatform,
            'summary' => Arr::get($this->payload, 'summary', []),
            'quotes' => $createdQuotes,
            'source_platform_updates' => $sourcePlatformUpdates,
        ];

        $destinationDispatch = $eventProcessingService
            ? $this->dispatchDestinationQuoteEvent($createdQuotes, $eventLoggingService, $eventProcessingService)
            : null;
        if ($destinationDispatch !== null) {
            $details['destination_event_dispatch'] = $destinationDispatch;
        }

        $this->record->update([
            'status' => 'success',
            'message' => 'Quote dispatched for destination creation',
            'details' => [
                ...$details,
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $createdQuotes
     * @return array<string, mixed>|null
     */
    private function dispatchDestinationQuoteEvent(
        array $createdQuotes,
        EventLoggingService $eventLoggingService,
        EventProcessingService $eventProcessingService
    ): ?array {
        $nextEvent = $this->event->to_event;
        if (! $nextEvent || ! $nextEvent->active) {
            return null;
        }

        $payload = [
            'quotes' => $createdQuotes,
            'source_event_id' => $this->event->id,
            'target_platform' => $nextEvent->platform?->type,
        ];

        if ($nextEvent->to_event_id === null) {
            $hubspotWritebackEvent = Event::query()
                ->where('platform_id', $this->event->platform_id)
                ->where('method_name', 'updateObject')
                ->where('active', true)
                ->where('id', '!=', $this->event->id)
                ->orderBy('id')
                ->first();

            if ($hubspotWritebackEvent) {
                $nextEvent->to_event_id = $hubspotWritebackEvent->id;
                $nextEvent->setRelation('to_event', $hubspotWritebackEvent);
            }
        }

        $destinationRecord = $eventLoggingService->createEventRecord(
            $nextEvent->event_type_id ?? $nextEvent->name,
            'init',
            $payload,
            'Dispatching destination quote/subscription creation',
            $this->record->id,
            $nextEvent->id,
            [
                'source_job' => self::class,
                'quotes_total' => count($createdQuotes),
            ]
        );

        $eventProcessingService->dispatchEvent($nextEvent, $destinationRecord, $payload);

        return [
            'next_event_id' => $nextEvent->id,
            'next_event_name' => $nextEvent->name,
            'record_id' => $destinationRecord->id,
            'quotes_total' => count($createdQuotes),
        ];
    }

    /**
     * @param  array<string, mixed>  $quote
     * @param  array<string, mixed>  $executionResponse
     * @return array<string, mixed>
     */
    private function storeExecutionResponseInSourcePlatform(
        array $quote,
        string $targetPlatform,
        array $executionResponse,
        HubspotApiServiceRefactored $hubspotApi,
        SignedQuotesPipelineService $pipelineService
    ): array {
        $sourcePlatformType = strtolower((string) ($this->event->platform->type ?? ''));
        if ($sourcePlatformType !== 'hubspot') {
            return [
                'success' => false,
                'skipped' => true,
                'reason' => 'source_platform_not_supported',
                'source_platform' => $sourcePlatformType !== '' ? $sourcePlatformType : null,
            ];
        }

        $hubspotQuoteId = trim((string) Arr::get($quote, 'hubspot_quote_id', ''));
        if ($hubspotQuoteId === '') {
            return [
                'success' => false,
                'skipped' => true,
                'reason' => 'missing_hubspot_quote_id',
                'source_platform' => 'hubspot',
            ];
        }

        $properties = $pipelineService->buildHubspotQuoteSyncProperties($quote, $targetPlatform, $executionResponse);
        $response = $hubspotApi->updateObject('quotes', $hubspotQuoteId, $properties);

        return [
            'success' => (bool) ($response['success'] ?? false),
            'skipped' => false,
            'source_platform' => 'hubspot',
            'hubspot_quote_id' => $hubspotQuoteId,
            'properties' => $properties,
            'response' => [
                'status_code' => $response['status_code'] ?? null,
                'data' => $response['data'] ?? null,
                'error' => $response['error'] ?? null,
                'message' => $response['message'] ?? null,
            ],
        ];
    }
}

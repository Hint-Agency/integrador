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
use Illuminate\Support\Arr;

class ResolveAssociationsJob implements ShouldQueue
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
        $this->onQueue('processing');
    }

    public function handle(EventLoggingService $eventLoggingService): void
    {
        $this->record->update([
            'status' => 'processing',
            'message' => 'Resolving quote associations',
        ]);

        $quotes = [];
        foreach (Arr::get($this->payload, 'quotes', []) as $quote) {
            if (! is_array($quote)) {
                continue;
            }

            $quotes[] = $quote + [
                'resolved_associations' => $this->resolveQuoteAssociations($quote),
            ];
        }

        $updateRecord = $eventLoggingService->createEventRecord(
            $this->event->event_type_id ?? 'signed_quotes',
            'init',
            [
                ...$this->payload,
                'quotes' => $quotes,
            ],
            'Updating HubSpot with entity sync metadata',
            $this->record->id,
            $this->event->id,
            [
                'job' => self::class,
                'quotes_total' => count($quotes),
            ]
        );

        UpdateHubSpotJob::dispatch([
            ...$this->payload,
            'quotes' => $quotes,
        ], $this->event, $updateRecord)->onQueue('update');

        $this->record->update([
            'status' => 'success',
            'message' => 'Quote associations resolved',
            'details' => [
                'quotes_total' => count($quotes),
                'next_job' => UpdateHubSpotJob::class,
            ],
        ]);
    }

    private function resolveQuoteAssociations(array $quote): array
    {
        $contacts = Arr::get($quote, 'raw.associations.contacts', Arr::get($quote, 'raw.contacts', []));
        if (! is_array($contacts)) {
            $contacts = [];
        }

        $billing = [];
        $shipping = [];
        $generic = [];

        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $role = strtolower((string) (
                Arr::get($contact, 'properties.contact_type')
                ?? Arr::get($contact, 'role')
                ?? Arr::get($contact, 'association_label')
                ?? Arr::get($contact, 'type', '')
            ));
            if (str_contains($role, 'billing') || str_contains($role, 'factur')) {
                $billing[] = $contact;
            } elseif (str_contains($role, 'shipping') || str_contains($role, 'delivery') || str_contains($role, 'entrega')) {
                $shipping[] = $contact;
            } else {
                $generic[] = $contact;
            }
        }

        return [
            'billing_contacts' => $billing,
            'shipping_contacts' => $shipping,
            'contacts' => $generic,
        ];
    }
}

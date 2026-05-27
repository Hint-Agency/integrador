<?php

namespace Tests\Feature;

use App\Jobs\CreateQuoteJob;
use App\Models\Event;
use App\Models\Platform;
use App\Models\Record;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateQuoteJobSourceWritebackTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_execution_metadata_back_in_hubspot_after_quote_creation(): void
    {
        Queue::fake();

        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Signed Quotes',
            'event_type_id' => 'quotes.sending_data',
            'type' => 'webhook',
            'method_name' => 'getSignedQuotes',
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'quotes.sending_data',
            'status' => 'init',
            'payload' => [],
            'message' => 'Queued',
        ]);

        $job = new CreateQuoteJob([
            'target_platform' => 'odoo',
            'summary' => ['create' => 1, 'update' => 1, 'no_change' => 0],
            'quotes' => [[
                'quote_id' => 'Q-100',
                'hubspot_quote_id' => 'hsq_123',
                'entity_results' => [
                    'company' => [
                        'operation' => 'create',
                        'changed_fields' => ['name'],
                    ],
                    'contact' => [
                        'operation' => 'update',
                        'changed_fields' => ['email'],
                    ],
                    'products' => [],
                ],
                'hubspot_sync_metadata' => [
                    'company' => [
                        'last_sync_odoo' => now()->subMinute()->toISOString(),
                        'sync_operation_odoo' => 'create',
                        'updated_fields_odoo' => ['name'],
                    ],
                    'contact' => [
                        'last_sync_odoo' => now()->subMinute()->toISOString(),
                        'sync_operation_odoo' => 'update',
                        'updated_fields_odoo' => ['email'],
                    ],
                    'products' => [],
                ],
            ]],
        ], $event->fresh('platform'), $record);

        $job->handle(
            app(\App\Services\EventLoggingService::class),
            app(\App\Services\Hubspot\HubspotApiServiceRefactored::class),
            app(\App\Services\SignedQuotesPipelineService::class)
        );

        $record->refresh();

        $this->assertSame('success', $record->status);
        $this->assertSame('Quote dispatched for destination creation', $record->message);
        $this->assertSame('pending_destination_creation', $record->details['quotes'][0]['status'] ?? null);
        $this->assertSame([], $record->details['source_platform_updates'] ?? null);
    }
}

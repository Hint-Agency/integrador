<?php

namespace Tests\Feature;

use App\Jobs\BuildCanonicalPayloadJob;
use App\Jobs\ProcessSignedQuotesJob;
use App\Jobs\ValidateEntitiesJob;
use App\Models\Event;
use App\Models\Platform;
use App\Models\Record;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use App\Services\Hubspot\HubspotService;
use App\Services\Hubspot\ProductCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class SignedQuotesInternalJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_signed_quotes_processing_dispatches_canonical_payload_job(): void
    {
        Bus::fake();

        $platform = Platform::query()->create([
            'name' => 'HubSpot directo',
            'slug' => 'hubspot-directo',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Fetch Signed Quotes',
            'event_type_id' => 'quotes.sending_data',
            'method_name' => 'getSignedQuotes',
            'type' => 'schedule',
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'quotes.sending_data',
            'status' => 'init',
            'payload' => [],
            'message' => 'Testing signed quotes',
        ]);

        (new ProcessSignedQuotesJob([
            [
                'quote_id' => 'Q-1',
                'hubspot_quote_id' => 'HSQ-1',
                'entities' => [],
            ],
        ], $event, $record))->handle(app(\App\Services\EventLoggingService::class));

        Bus::assertDispatched(BuildCanonicalPayloadJob::class);
        $this->assertSame('success', $record->refresh()->status);
    }

    public function test_get_signed_quotes_dispatches_one_job_per_quote(): void
    {
        Bus::fake();

        $platform = Platform::query()->create([
            'name' => 'HubSpot directo',
            'slug' => 'hubspot-directo',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Fetch Signed Quotes',
            'event_type_id' => 'quotes.sending_data',
            'method_name' => 'getSignedQuotes',
            'type' => 'schedule',
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'quotes.sending_data',
            'status' => 'init',
            'payload' => [],
            'message' => 'Testing signed quotes',
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchSignedQuotes')
            ->with($event)
            ->once()
            ->andReturn([
                'success' => true,
                'data' => [
                    'results' => [
                        ['id' => 'q1'],
                        ['id' => 'q2'],
                    ],
                ],
            ]);

        $service = new HubspotService($platform, $event, $record, $hubspotApi, Mockery::mock(ProductCacheService::class));
        $result = $service->getSignedQuotes();

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame(2, $result['data']['queued_count']);
        Bus::assertDispatchedTimes(ProcessSignedQuotesJob::class, 2);
    }

    public function test_get_signed_quotes_does_not_create_sample_quote_when_hubspot_returns_empty(): void
    {
        Bus::fake();

        $platform = Platform::query()->create([
            'name' => 'HubSpot directo',
            'slug' => 'hubspot-directo-empty',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Fetch Signed Quotes',
            'event_type_id' => 'quotes.sending_data',
            'method_name' => 'getSignedQuotes',
            'type' => 'schedule',
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'quotes.sending_data',
            'status' => 'init',
            'payload' => [],
            'message' => 'Testing signed quotes',
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchSignedQuotes')
            ->with($event)
            ->once()
            ->andReturn([
                'success' => true,
                'data' => [
                    'results' => [],
                ],
            ]);

        $service = new HubspotService($platform, $event, $record, $hubspotApi, Mockery::mock(ProductCacheService::class));
        $result = $service->getSignedQuotes();

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['count']);
        Bus::assertNotDispatched(ProcessSignedQuotesJob::class);
    }

    public function test_get_archived_quotes_only_returns_quotes_synced_successfully_to_odoo(): void
    {
        $platform = Platform::query()->create([
            'name' => 'HubSpot directo',
            'slug' => 'hubspot-directo-archived',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Fetch Archived Quotes',
            'event_type_id' => 'quotes.sending_data',
            'method_name' => 'getArchivedQuotes',
            'type' => 'schedule',
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'quotes.sending_data',
            'status' => 'init',
            'payload' => [],
            'message' => 'Testing archived quotes',
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('request')
            ->once()
            ->with('GET', '/crm/v3/objects/quotes', [], Mockery::on(fn (array $query): bool => ($query['archived'] ?? null) === true
                && ($query['limit'] ?? null) === 100
                && str_contains((string) ($query['properties'] ?? ''), 'sync_status_odoo')))
            ->andReturn([
                'success' => true,
                'data' => [
                    'results' => [
                        [
                            'id' => 'quote_success',
                            'properties' => [
                                'sync_status_odoo' => 'success',
                                'hs_quote_number' => 'Q-SUCCESS',
                            ],
                        ],
                        [
                            'id' => 'quote_error',
                            'properties' => [
                                'sync_status_odoo' => 'error',
                                'hs_quote_number' => 'Q-ERROR',
                            ],
                        ],
                        [
                            'id' => 'quote_missing_status',
                            'properties' => [
                                'hs_quote_number' => 'Q-MISSING',
                            ],
                        ],
                    ],
                ],
            ]);

        $service = new HubspotService($platform, $event, $record, $hubspotApi, Mockery::mock(ProductCacheService::class));
        $result = $service->getArchivedQuotes();

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['count']);
        $this->assertSame(3, $result['data']['archived_count']);
        $this->assertSame(2, $result['data']['filtered_count']);
        $this->assertSame('quote_success', $result['data']['output_payload']['quotes'][0]['id']);
    }

    public function test_canonical_payload_skips_quotes_already_marked_synced_in_hubspot(): void
    {
        Bus::fake();

        $platform = Platform::query()->create([
            'name' => 'HubSpot directo',
            'slug' => 'hubspot-directo-synced',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Fetch Signed Quotes',
            'event_type_id' => 'quotes.sending_data',
            'method_name' => 'getSignedQuotes',
            'type' => 'schedule',
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'quotes.sending_data',
            'status' => 'init',
            'payload' => [],
            'message' => 'Testing synced quote',
        ]);

        (new BuildCanonicalPayloadJob([
            'quotes' => [[
                'id' => 'hsq_1',
                'properties' => [
                    'sync_status_odoo' => 'success',
                    'odoo_id' => 901,
                ],
            ]],
        ], $event, $record))->handle(
            app(\App\Services\EventLoggingService::class),
            app(\App\Services\SignedQuotesPipelineService::class)
        );

        Bus::assertNotDispatched(ValidateEntitiesJob::class);
        $this->assertSame('No pending signed quotes to process', $record->refresh()->message);
        $this->assertDatabaseHas('records', [
            'record_id' => $record->id,
            'status' => 'success',
            'message' => 'Quote already synced to Odoo',
        ]);
    }
}

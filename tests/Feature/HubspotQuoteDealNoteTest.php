<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Platform;
use App\Models\Record;
use App\Services\Hubspot\HubspotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubspotQuoteDealNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_writeback_adds_success_note_to_associated_deal(): void
    {
        [$platform, $event] = $this->hubspotQuoteWritebackContext();

        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'object.updated',
            'status' => 'init',
            'payload' => [
                'id' => 'quote_123',
                'quote_id' => 'Q-123',
                'deal_id' => 'deal_456',
                'operation' => 'created',
                'properties' => [
                    'odoo_id' => 901,
                    'sync_status_odoo' => 'success',
                    'last_sync_odoo' => now()->toISOString(),
                    'last_error_odoo' => '',
                ],
            ],
            'message' => 'Quote writeback',
        ]);

        Http::fake([
            'https://api.hubapi.test/crm/v3/objects/quotes/quote_123' => Http::response(['id' => 'quote_123'], 200),
            'https://api.hubapi.test/crm/v3/objects/notes' => Http::response(['id' => 'note_901'], 201),
            'https://api.hubapi.test/crm/v3/objects/notes/note_901/associations/deal/deal_456/214' => Http::response([], 204),
            'https://api.hubapi.test/crm/v3/objects/deal/deal_456/associations/notes/note_901/213' => Http::response([], 204),
        ]);

        $result = app()->make(HubspotService::class, [
            'platform' => $platform,
            'event' => $event,
            'record' => $record,
        ])->updateObject();

        $this->assertTrue($result['success']);
        $this->assertTrue((bool) ($result['data']['hubspot_deal_note']['success'] ?? false));
        $this->assertSame('deal_456', $result['data']['hubspot_deal_note']['deal_id'] ?? null);

        Http::assertSent(function ($request): bool {
            $body = $request->data()['properties']['hs_note_body'] ?? '';

            return $request->url() === 'https://api.hubapi.test/crm/v3/objects/notes'
                && str_contains($body, 'Cotizacion sincronizada con Odoo')
                && str_contains($body, 'Odoo ID: 901');
        });
    }

    public function test_quote_writeback_adds_error_note_to_associated_deal_when_update_fails(): void
    {
        [$platform, $event] = $this->hubspotQuoteWritebackContext();

        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'object.updated',
            'status' => 'init',
            'payload' => [
                'id' => 'quote_123',
                'quote_id' => 'Q-123',
                'deal_id' => 'deal_456',
                'operation' => 'error',
                'properties' => [
                    'sync_status_odoo' => 'error',
                    'last_sync_odoo' => now()->toISOString(),
                    'last_error_odoo' => 'Odoo rejected the subscription.',
                ],
            ],
            'message' => 'Quote writeback',
        ]);

        Http::fake([
            'https://api.hubapi.test/crm/v3/objects/quotes/quote_123' => Http::response([
                'message' => 'Quote update rejected.',
            ], 400),
            'https://api.hubapi.test/crm/v3/objects/notes' => Http::response(['id' => 'note_902'], 201),
            'https://api.hubapi.test/crm/v3/objects/notes/note_902/associations/deal/deal_456/214' => Http::response([], 204),
            'https://api.hubapi.test/crm/v3/objects/deal/deal_456/associations/notes/note_902/213' => Http::response([], 204),
        ]);

        $result = app()->make(HubspotService::class, [
            'platform' => $platform,
            'event' => $event,
            'record' => $record,
        ])->updateObject();

        $this->assertFalse($result['success']);
        $this->assertTrue((bool) ($result['data']['hubspot_deal_note']['success'] ?? false));
        $this->assertSame('deal_456', $result['data']['hubspot_deal_note']['deal_id'] ?? null);

        Http::assertSent(function ($request): bool {
            $body = $request->data()['properties']['hs_note_body'] ?? '';

            return $request->url() === 'https://api.hubapi.test/crm/v3/objects/notes'
                && str_contains($body, 'Error al sincronizar cotizacion con Odoo')
                && str_contains($body, 'Odoo rejected the subscription.');
        });
    }

    public function test_quote_specific_writeback_updates_each_quote_payload_from_list(): void
    {
        [$platform, $event] = $this->hubspotQuoteWritebackContext([
            'method_name' => 'updateQuoteObject',
        ]);

        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'object.updated',
            'status' => 'init',
            'payload' => [
                [
                    'id' => 'quote_123',
                    'quote_id' => 'quote_123',
                    'deal_id' => 'deal_456',
                    'operation' => 'created',
                    'properties' => [
                        'odoo_id' => 901,
                        'sync_status_odoo' => 'success',
                        'last_sync_odoo' => now()->toISOString(),
                        'last_error_odoo' => '',
                    ],
                    'destination_response' => [
                        'data' => [
                            'id' => 901,
                            'model' => 'dp.sale.subscription',
                            'operation' => 'created',
                        ],
                    ],
                ],
            ],
            'message' => 'Quote writeback',
        ]);

        Http::fake([
            'https://api.hubapi.test/crm/v3/objects/quotes/quote_123' => Http::response(['id' => 'quote_123'], 200),
            'https://api.hubapi.test/crm/v3/objects/notes' => Http::response(['id' => 'note_901'], 201),
            'https://api.hubapi.test/crm/v3/objects/notes/note_901/associations/deal/deal_456/214' => Http::response([], 204),
            'https://api.hubapi.test/crm/v3/objects/deal/deal_456/associations/notes/note_901/213' => Http::response([], 204),
        ]);

        $result = app()->make(HubspotService::class, [
            'platform' => $platform,
            'event' => $event,
            'record' => $record,
        ])->updateQuoteObject();

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['updated_count']);
        $this->assertSame(0, $result['data']['error_count']);
        $this->assertSame('quote_123', $result['data']['results'][0]['object_id'] ?? null);
        $this->assertTrue((bool) ($result['data']['results'][0]['hubspot_deal_note']['success'] ?? false));

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.hubapi.test/crm/v3/objects/quotes/quote_123'
                && ($request->data()['properties']['odoo_id'] ?? null) === 901;
        });
    }

    public function test_quote_specific_writeback_returns_detailed_error_when_quote_id_is_missing(): void
    {
        [$platform, $event] = $this->hubspotQuoteWritebackContext([
            'method_name' => 'updateQuoteObject',
        ]);

        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'object.updated',
            'status' => 'init',
            'payload' => [
                [
                    'deal_id' => 'deal_456',
                    'properties' => [
                        'sync_status_odoo' => 'success',
                    ],
                ],
            ],
            'message' => 'Quote writeback',
        ]);

        Http::fake();

        $result = app()->make(HubspotService::class, [
            'platform' => $platform,
            'event' => $event,
            'record' => $record,
        ])->updateQuoteObject();

        $this->assertFalse($result['success']);
        $this->assertSame('missing_hubspot_quote_id', $result['data']['errors'][0]['reason'] ?? null);
        $this->assertContains('hubspot_quote_id', $result['data']['errors'][0]['accepted_id_fields'] ?? []);
        $this->assertContains('properties', $result['data']['errors'][0]['payload_keys'] ?? []);

        Http::assertNothingSent();
    }

    public function test_archived_quote_cancellation_writeback_adds_note_to_associated_deal(): void
    {
        [$platform, $event] = $this->hubspotQuoteWritebackContext([
            'name' => 'Write Back Archived Quote Cancellation',
            'method_name' => 'writeBackArchivedQuoteCancellation',
        ]);

        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'object.updated',
            'status' => 'init',
            'payload' => [
                [
                    'id' => 'quote_123',
                    'quote_id' => 'quote_123',
                    'deal_id' => 'deal_456',
                    'operation' => 'cancelled',
                    'properties' => [
                        'sync_status_odoo' => 'cancelled',
                        'last_sync_odoo' => now()->toISOString(),
                        'last_error_odoo' => '',
                    ],
                    'destination_response' => [
                        'data' => [
                            'id' => 256,
                            'model' => 'dp.sale.subscription',
                            'operation' => 'cancelled',
                            'status' => 'cancelled',
                        ],
                    ],
                ],
            ],
            'message' => 'Archived quote cancellation writeback',
        ]);

        Http::fake([
            'https://api.hubapi.test/crm/v3/objects/quotes/quote_123' => Http::response(['id' => 'quote_123'], 200),
            'https://api.hubapi.test/crm/v3/objects/notes' => Http::response(['id' => 'note_cancel'], 201),
            'https://api.hubapi.test/crm/v3/objects/notes/note_cancel/associations/deal/deal_456/214' => Http::response([], 204),
            'https://api.hubapi.test/crm/v3/objects/deal/deal_456/associations/notes/note_cancel/213' => Http::response([], 204),
        ]);

        $result = app()->make(HubspotService::class, [
            'platform' => $platform,
            'event' => $event,
            'record' => $record,
        ])->writeBackArchivedQuoteCancellation();

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['updated_count']);
        $this->assertTrue((bool) ($result['data']['results'][0]['hubspot_deal_note']['success'] ?? false));

        Http::assertSent(function ($request): bool {
            $body = $request->data()['properties']['hs_note_body'] ?? '';

            return $request->url() === 'https://api.hubapi.test/crm/v3/objects/notes'
                && str_contains($body, 'Suscripcion cancelada en Odoo')
                && str_contains($body, 'Suscripcion Odoo: 256')
                && str_contains($body, 'Estado: cancelled');
        });
    }

    /**
     * @return array{0: Platform, 1: Event}
     */
    private function hubspotQuoteWritebackContext(array $eventOverrides = []): array
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');
        config()->set('hubspot.note_association_type_ids.deals', 214);

        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'settings' => [
                'base_url' => 'https://api.hubapi.test',
            ],
        ]);

        $event = Event::query()->create(array_merge([
            'platform_id' => $platform->id,
            'name' => 'Write Back Source Quote',
            'event_type_id' => 'object.updated',
            'type' => 'job',
            'method_name' => 'updateObject',
            'active' => true,
            'meta' => [
                'object_type' => 'quotes',
            ],
        ], $eventOverrides));

        return [$platform, $event];
    }
}

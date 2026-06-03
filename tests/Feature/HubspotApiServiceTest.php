<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubspotApiServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ping_returns_success_when_hubspot_api_responds_ok(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');
        $event = $this->signedQuotesEventWithMappings();

        Http::fake([
            'https://api.hubapi.test/integrations/v1/me' => Http::response([
                'portalId' => 12345,
            ], 200),
        ]);

        $service = app(HubspotApiServiceRefactored::class);
        $result = $service->ping();

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status_code']);
        $this->assertSame(12345, $result['data']['portalId']);
    }

    public function test_search_signed_quotes_uses_configured_filter(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-25 12:00:00', 'UTC'));

        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');
        $event = $this->signedQuotesEventWithMappings();

        Http::fake([
            'https://api.hubapi.test/crm/v3/objects/quotes/search' => Http::response([
                'results' => [
                    ['id' => 'q1'],
                ],
            ], 200),
            'https://api.hubapi.test/crm/v4/objects/quotes/q1/associations/companies' => Http::response([
                'results' => [
                    ['toObjectId' => 'company_1'],
                ],
            ], 200),
            'https://api.hubapi.test/crm/v4/objects/quotes/q1/associations/contacts' => Http::response([
                'results' => [
                    ['toObjectId' => 'contact_1', 'associationTypes' => [['label' => 'Billing']]],
                ],
            ], 200),
            'https://api.hubapi.test/crm/v4/objects/quotes/q1/associations/deals' => Http::response([
                'results' => [
                    ['toObjectId' => 'deal_1'],
                ],
            ], 200),
            'https://api.hubapi.test/crm/v4/objects/quotes/q1/associations/line_items' => Http::response([
                'results' => [
                    ['toObjectId' => 'line_1'],
                ],
            ], 200),
            'https://api.hubapi.test/crm/v3/objects/companies/company_1*' => Http::response([
                'id' => 'company_1',
                'properties' => ['name' => 'Demo Company'],
            ], 200),
            'https://api.hubapi.test/crm/v3/objects/contacts/contact_1*' => Http::response([
                'id' => 'contact_1',
                'properties' => ['email' => 'demo@example.com'],
            ], 200),
            'https://api.hubapi.test/crm/v3/objects/deals/deal_1*' => Http::response([
                'id' => 'deal_1',
                'properties' => [
                    'dealname' => 'Demo Deal',
                    'hubspot_owner_id' => 'owner_1',
                ],
            ], 200),
            'https://api.hubapi.test/crm/v3/owners/owner_1*' => Http::response([
                'id' => 'owner_1',
                'email' => 'owner@example.com',
                'firstName' => 'Demo',
                'lastName' => 'Owner',
                'userId' => 123,
                'teams' => [
                    ['id' => 'team_1', 'name' => 'Sales', 'primary' => true],
                ],
            ], 200),
            'https://api.hubapi.test/crm/v3/objects/line_items/line_1*' => Http::response([
                'id' => 'line_1',
                'properties' => ['name' => 'Demo product', 'quantity' => '1', 'price' => '100'],
            ], 200),
        ]);

        $service = app(HubspotApiServiceRefactored::class);
        $result = $service->searchSignedQuotes($event);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']['results']);
        $this->assertSame('Demo Company', $result['data']['results'][0]['entities']['company']['fields']['name'] ?? null);
        $this->assertSame('demo@example.com', $result['data']['results'][0]['entities']['contact']['fields']['email'] ?? null);
        $this->assertSame('Demo product', $result['data']['results'][0]['entities']['products'][0]['fields']['name'] ?? null);
        $this->assertSame('deal_1', $result['data']['results'][0]['deal_id'] ?? null);
        $this->assertSame('owner@example.com', $result['data']['results'][0]['associations']['deals'][0]['owner']['email'] ?? null);
        $this->assertSame('Demo Owner', $result['data']['results'][0]['associations']['deals'][0]['owner']['full_name'] ?? null);

        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $firstGroupFilters = $body['filterGroups'][0]['filters'] ?? [];
            $secondGroupFilters = $body['filterGroups'][1]['filters'] ?? [];
            $allFilters = collect($body['filterGroups'] ?? [])->pluck('filters');

            return count($body['filterGroups'] ?? []) === 4
                && in_array([
                    'propertyName' => 'hs_sign_status',
                    'operator' => 'IN',
                    'values' => ['SIGNED', 'MANUALLY_SIGNED'],
                ], $firstGroupFilters, true)
                && in_array([
                    'propertyName' => 'hs_last_published_date',
                    'operator' => 'BETWEEN',
                    'value' => 1779537600000,
                    'highValue' => 1779710400000,
                ], $firstGroupFilters, true)
                && $allFilters->contains(fn ($filters): bool => in_array([
                    'propertyName' => 'hs_createdate',
                    'operator' => 'BETWEEN',
                    'value' => 1779537600000,
                    'highValue' => 1779710400000,
                ], $filters, true))
                && in_array([
                    'propertyName' => 'hs_archived',
                    'operator' => 'NOT_HAS_PROPERTY',
                ], $firstGroupFilters, true)
                && in_array([
                    'propertyName' => 'sync_status_odoo',
                    'operator' => 'NOT_HAS_PROPERTY',
                ], $firstGroupFilters, true)
                && in_array([
                    'propertyName' => 'sync_status_odoo',
                    'operator' => 'NOT_IN',
                    'values' => ['success', 'already_exists'],
                ], $secondGroupFilters, true)
                && ($body['sorts'][0] ?? null) === [
                    'propertyName' => 'hs_last_published_date',
                    'direction' => 'DESCENDING',
                ]
                && in_array('hs_last_published_date', $body['properties'] ?? [], true)
                && in_array('last_error_odoo', $body['properties'] ?? [], true);
        });

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/crm/v3/objects/companies/company_1')
            && str_contains(urldecode($request->url()), 'razon_social_de_la_empresa'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/crm/v3/objects/contacts/contact_1')
            && str_contains(urldecode($request->url()), 'email'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/crm/v3/objects/line_items/line_1')
            && str_contains(urldecode($request->url()), 'price'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/crm/v3/objects/deals/deal_1')
            && str_contains(urldecode($request->url()), 'hubspot_owner_id'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/crm/v3/owners/owner_1')
            && str_contains(urldecode($request->url()), 'idProperty=id'));

        Carbon::setTestNow();
    }

    public function test_search_signed_quotes_fetches_all_pages(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        Http::fake([
            'https://api.hubapi.test/crm/v3/objects/quotes/search' => Http::sequence()
                ->push([
                    'results' => [
                        ['id' => 'q1'],
                    ],
                    'paging' => [
                        'next' => [
                            'after' => 'page_2',
                        ],
                    ],
                ], 200)
                ->push([
                    'results' => [
                        ['id' => 'q2'],
                    ],
                ], 200),
            'https://api.hubapi.test/crm/v4/objects/*' => Http::response([
                'results' => [],
            ], 200),
        ]);

        $service = app(HubspotApiServiceRefactored::class);
        $result = $service->searchSignedQuotes();

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['total']);
        $this->assertSame(['q1', 'q2'], array_column($result['data']['results'], 'id'));

        Http::assertSent(fn ($request): bool => ($request->data()['after'] ?? null) === 'page_2');
    }

    public function test_add_note_to_object_creates_associated_note(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');
        config()->set('hubspot.note_association_type_ids.contacts', 202);

        Http::fake([
            'https://api.hubapi.test/crm/v3/objects/notes' => Http::response([
                'id' => '9001',
            ], 201),
            'https://api.hubapi.test/crm/v3/objects/notes/9001/associations/contact/123/202' => Http::response([], 204),
            'https://api.hubapi.test/crm/v3/objects/contact/123/associations/notes/9001/201' => Http::response([], 204),
        ]);

        $service = app(HubspotApiServiceRefactored::class);
        $result = $service->addNoteToObject('contacts', '123', 'Contact sync failed', [
            'event_id' => 10,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(201, $result['status_code']);
        $this->assertSame('9001', $result['data']['id']);
        $this->assertTrue((bool) ($result['association']['success'] ?? false));
        $this->assertTrue((bool) ($result['timeline_association']['success'] ?? false));
    }

    public function test_add_note_to_object_includes_multiline_message_body(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');
        config()->set('hubspot.note_association_type_ids.contacts', 202);

        Http::fake([
            'https://api.hubapi.test/crm/v3/objects/notes' => Http::response([
                'id' => '9002',
            ], 201),
            'https://api.hubapi.test/crm/v3/objects/notes/9002/associations/contact/123/202' => Http::response([], 204),
            'https://api.hubapi.test/crm/v3/objects/contact/123/associations/notes/9002/201' => Http::response([], 204),
        ]);

        $service = app(HubspotApiServiceRefactored::class);
        $result = $service->addNoteToObject(
            'contacts',
            '123',
            "[Integrador] Error de sincronizacion de contacto\nOperacion: write-back a HubSpot\nPropiedad: hs_object_id",
            ['event_id' => 10]
        );

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request): bool {
            $body = $request->data()['properties']['hs_note_body'] ?? '';

            return str_contains($body, '[Integrador] Error de sincronizacion de contacto')
                && str_contains($body, 'Operacion: write-back a HubSpot')
                && str_contains($body, 'Propiedad: hs_object_id');
        });

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://api.hubapi.test/crm/v3/objects/notes/9002/associations/contact/123/202');
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contact/123/associations/notes/9002/201');
    }

    private function signedQuotesEventWithMappings(): Event
    {
        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Fetch Signed Quotes',
            'event_type_id' => 'quotes.sending_data',
            'type' => 'schedule',
            'method_name' => 'getSignedQuotes',
            'active' => true,
        ]);

        foreach ([
            'company.razon_social_de_la_empresa',
            'contact.email',
            'product.price',
            'deal.hs_object_id',
            'hs_quote_number',
        ] as $key) {
            $source = Property::query()->create([
                'platform_id' => $platform->id,
                'name' => $key,
                'key' => $key,
                'type' => 'string',
                'active' => true,
            ]);

            $target = Property::query()->create([
                'platform_id' => $platform->id,
                'name' => 'target_'.$key,
                'key' => 'target_'.$key,
                'type' => 'string',
                'active' => true,
            ]);

            PropertyRelationship::query()->create([
                'event_id' => $event->id,
                'property_id' => $source->id,
                'related_property_id' => $target->id,
                'active' => true,
            ]);
        }

        return $event;
    }
}

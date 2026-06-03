<?php

namespace Tests\Feature;

use App\Jobs\CreateOrUpdateEntityJob;
use App\Jobs\ResolveAssociationsJob;
use App\Models\Event;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use App\Models\Record;
use App\Services\EventLoggingService;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use App\Services\SignedQuotesPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateOrUpdateEntityJobOdooUpsertTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_upserts_company_and_contact_in_odoo_and_writes_ids_back_to_hubspot(): void
    {
        Queue::fake([ResolveAssociationsJob::class]);

        [$event, $record] = $this->signedQuoteContext();

        $partnerCreateCount = 0;
        Http::fake(function ($request) use (&$partnerCreateCount) {
            if ($request->url() === 'https://api.hubapi.test/crm/v3/objects/companies/company_123') {
                return Http::response(['id' => 'company_123'], 200);
            }

            if ($request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts/contact_123') {
                return Http::response(['id' => 'contact_123'], 200);
            }

            if ($request->url() !== 'https://odoo-directo.test/jsonrpc') {
                return Http::response([], 404);
            }

            $params = $request->data()['params'] ?? [];
            if (($params['service'] ?? null) === 'common') {
                return Http::response(['result' => 7], 200);
            }

            $args = $params['args'] ?? [];
            $model = $args[3] ?? null;
            $method = $args[4] ?? null;

            if ($model === 'res.partner' && $method === 'create') {
                $partnerCreateCount++;

                return Http::response(['result' => $partnerCreateCount === 1 ? 501 : 777], 200);
            }

            return Http::response(['result' => []], 200);
        });

        $job = new CreateOrUpdateEntityJob([
            'target_platform' => 'odoo',
            'quotes' => [[
                'quote_id' => 'Q-501',
                'hubspot_quote_id' => 'quote_501',
                'entity_actions' => [
                    'company' => [
                        'action' => 'create',
                        'entity_type' => 'company',
                        'entity' => [
                            'hubspot_id' => 'company_123',
                        ],
                        'fields' => [
                            'razon_social_de_la_empresa' => 'Directo Group',
                            'rfc' => 'DG010101AA1',
                            'hs_object_id' => 'company_123',
                            'odoo_id' => '999',
                        ],
                        'changed_fields' => ['razon_social_de_la_empresa'],
                    ],
                    'contact' => [
                        'action' => 'create',
                        'entity_type' => 'contact',
                        'entity' => [
                            'hubspot_id' => 'contact_123',
                        ],
                        'fields' => [
                            'firstname' => 'Ana',
                            'email' => 'ana@example.test',
                            'hs_object_id' => 'contact_123',
                        ],
                        'changed_fields' => ['email'],
                    ],
                    'products' => [],
                ],
                'raw' => [],
            ]],
        ], $event, $record);

        $job->handle(
            app(EventLoggingService::class),
            app(SignedQuotesPipelineService::class),
            app(HubspotApiServiceRefactored::class)
        );

        $record->refresh();

        $this->assertSame('success', $record->status, json_encode($record->details, JSON_PRETTY_PRINT));
        $this->assertTrue((bool) data_get($record->details, 'source_writebacks.0.entities.company.success'));
        $this->assertTrue((bool) data_get($record->details, 'source_writebacks.0.entities.contact.success'));

        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://api.hubapi.test/crm/v3/objects/companies/company_123'
            && data_get($request->data(), 'properties.odoo_id') === 501);

        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts/contact_123'
            && data_get($request->data(), 'properties.odoo_id') === 777);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://odoo-directo.test/jsonrpc') {
                return false;
            }

            $params = $request->data()['params']['args'] ?? [];
            if (($params[3] ?? null) !== 'res.partner' || ($params[4] ?? null) !== 'create') {
                return false;
            }

            $payload = $params[5][0] ?? [];
            if (! is_array($payload)) {
                return false;
            }

            return ($payload['vat'] ?? null) === 'DG010101AA1'
                && ($payload['x_studio_hubspot_id'] ?? null) === 'company_123'
                && ! array_key_exists('partner_id', $payload)
                && ! array_key_exists('odoo_id', $payload)
                && ! array_key_exists('rfc', $payload)
                && ! array_key_exists('razon_social_de_la_empresa', $payload);
        });

        Queue::assertPushed(ResolveAssociationsJob::class);
    }

    public function test_it_blocks_subscription_creation_when_partner_upsert_fails(): void
    {
        Queue::fake([ResolveAssociationsJob::class]);

        [$event, $record] = $this->signedQuoteContext();

        Http::fake([
            'https://odoo-directo.test/jsonrpc' => Http::sequence()
                ->push(['result' => 7], 200)->push(['result' => []], 200)
                ->push(['result' => 7], 200)->push(['result' => []], 200)
                ->push(['result' => 7], 200)->push(['result' => []], 200)
                ->push(['error' => ['message' => 'create failed']], 200),
        ]);

        $job = new CreateOrUpdateEntityJob([
            'target_platform' => 'odoo',
            'quotes' => [[
                'quote_id' => 'Q-ERR',
                'hubspot_quote_id' => 'quote_err',
                'entity_actions' => [
                    'company' => [
                        'action' => 'create',
                        'entity_type' => 'company',
                        'entity' => [
                            'hubspot_id' => 'company_err',
                        ],
                        'fields' => [
                            'razon_social_de_la_empresa' => 'Broken Company',
                            'rfc' => 'ERR010101AA1',
                            'hs_object_id' => 'company_err',
                        ],
                    ],
                    'contact' => [
                        'action' => 'create',
                        'entity_type' => 'contact',
                        'entity' => [
                            'hubspot_id' => 'contact_err',
                        ],
                        'fields' => [
                            'email' => 'broken@example.test',
                            'hs_object_id' => 'contact_err',
                        ],
                    ],
                    'products' => [],
                ],
                'raw' => [],
            ]],
        ], $event, $record);

        $job->handle(
            app(EventLoggingService::class),
            app(SignedQuotesPipelineService::class),
            app(HubspotApiServiceRefactored::class)
        );

        $record->refresh();

        $this->assertSame('error', $record->status);
        $this->assertSame('all_quotes_blocked_before_subscription_creation', data_get($record->details, 'reason'));
        $this->assertSame(1, data_get($record->details, 'blocked_count'));
        $this->assertSame('company_odoo_upsert_failed', data_get($record->details, 'blocked_quotes.0.reason'));
        $this->assertSame('company_odoo_upsert_failed', data_get($record->details, 'blocked_summary.0.reason'));
        $this->assertSame('Odoo company creation failed.', data_get($record->details, 'blocked_summary.0.message'));

        Queue::assertNotPushed(ResolveAssociationsJob::class);
    }

    public function test_it_sends_company_file_property_to_odoo_as_base64(): void
    {
        Queue::fake([ResolveAssociationsJob::class]);

        [$event, $record] = $this->signedQuoteContext();
        $odooEvent = Event::query()->findOrFail($event->to_event_id);

        $source = Property::query()->create([
            'platform_id' => $event->platform_id,
            'name' => 'Documento constancia de situación fiscal',
            'key' => 'company.documento_constancia_de_situacion_fiscal',
            'type' => 'file',
            'active' => true,
        ]);

        $target = Property::query()->create([
            'platform_id' => $odooEvent->platform_id,
            'name' => 'CSF Odoo',
            'key' => 'x_studio_constancia_de_situacion_fiscal',
            'type' => 'file',
            'active' => true,
        ]);

        PropertyRelationship::query()->create([
            'event_id' => $event->id,
            'property_id' => $source->id,
            'related_property_id' => $target->id,
            'active' => true,
        ]);

        $partnerCreateCount = 0;
        Http::fake(function ($request) use (&$partnerCreateCount) {
            if ($request->url() === 'https://api.hubapi.test/files/v3/files/268543396066/signed-url') {
                return Http::response([
                    'name' => 'constancia fiscal',
                    'extension' => 'pdf',
                    'url' => 'https://files.example.com/constancia',
                ], 200);
            }

            if ($request->url() === 'https://files.example.com/constancia') {
                return Http::response('pdf-content', 200, [
                    'Content-Type' => 'application/pdf',
                ]);
            }

            if ($request->url() === 'https://api.hubapi.test/crm/v3/objects/companies/company_file') {
                return Http::response(['id' => 'company_file'], 200);
            }

            if ($request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts/contact_file') {
                return Http::response(['id' => 'contact_file'], 200);
            }

            if ($request->url() !== 'https://odoo-directo.test/jsonrpc') {
                return Http::response([], 404);
            }

            $params = $request->data()['params'] ?? [];
            if (($params['service'] ?? null) === 'common') {
                return Http::response(['result' => 7], 200);
            }

            $args = $params['args'] ?? [];
            if (($args[3] ?? null) === 'res.partner' && ($args[4] ?? null) === 'create') {
                $partnerCreateCount++;

                return Http::response(['result' => $partnerCreateCount === 1 ? 501 : 777], 200);
            }

            return Http::response(['result' => []], 200);
        });

        $job = new CreateOrUpdateEntityJob([
            'target_platform' => 'odoo',
            'quotes' => [[
                'quote_id' => 'Q-FILE',
                'hubspot_quote_id' => 'quote_file',
                'entity_actions' => [
                    'company' => [
                        'action' => 'create',
                        'entity_type' => 'company',
                        'entity' => [
                            'hubspot_id' => 'company_file',
                        ],
                        'fields' => [
                            'razon_social_de_la_empresa' => 'Company With File',
                            'rfc' => 'FIL010101AA1',
                            'hs_object_id' => 'company_file',
                            'documento_constancia_de_situacion_fiscal' => '268543396066',
                        ],
                    ],
                    'contact' => [
                        'action' => 'create',
                        'entity_type' => 'contact',
                        'entity' => [
                            'hubspot_id' => 'contact_file',
                        ],
                        'fields' => [
                            'firstname' => 'Archivo',
                            'email' => 'archivo@example.test',
                            'hs_object_id' => 'contact_file',
                        ],
                    ],
                    'products' => [],
                ],
                'raw' => [],
            ]],
        ], $event, $record);

        $job->handle(
            app(EventLoggingService::class),
            app(SignedQuotesPipelineService::class),
            app(HubspotApiServiceRefactored::class)
        );

        $record->refresh();

        $this->assertSame('success', $record->status, json_encode($record->details, JSON_PRETTY_PRINT));

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://odoo-directo.test/jsonrpc') {
                return false;
            }

            $args = $request->data()['params']['args'] ?? [];
            if (($args[3] ?? null) !== 'res.partner' || ($args[4] ?? null) !== 'create') {
                return false;
            }

            $payload = $args[5][0] ?? [];

            return is_array($payload)
                && ($payload['x_studio_constancia_de_situacion_fiscal'] ?? null) === base64_encode('pdf-content')
                && ($payload['x_studio_constancia_de_situacion_fiscal_filename'] ?? null) === 'constancia fiscal.pdf';
        });
    }

    public function test_it_upserts_invoice_and_delivery_contacts_from_quote_associations(): void
    {
        Queue::fake([ResolveAssociationsJob::class]);

        [$event, $record] = $this->signedQuoteContext();

        $partnerCreateCount = 0;
        Http::fake(function ($request) use (&$partnerCreateCount) {
            if (str_starts_with($request->url(), 'https://api.hubapi.test/crm/v3/objects/companies/')
                || str_starts_with($request->url(), 'https://api.hubapi.test/crm/v3/objects/contacts/')) {
                return Http::response(['id' => basename($request->url())], 200);
            }

            if ($request->url() !== 'https://odoo-directo.test/jsonrpc') {
                return Http::response([], 404);
            }

            $params = $request->data()['params'] ?? [];
            if (($params['service'] ?? null) === 'common') {
                return Http::response(['result' => 7], 200);
            }

            $args = $params['args'] ?? [];
            $model = $args[3] ?? null;
            $method = $args[4] ?? null;

            if ($model === 'res.partner' && $method === 'create') {
                $partnerCreateCount++;

                return Http::response(['result' => match ($partnerCreateCount) {
                    1 => 501,
                    2 => 777,
                    default => 778,
                }], 200);
            }

            return Http::response(['result' => []], 200);
        });

        $job = new CreateOrUpdateEntityJob([
            'target_platform' => 'odoo',
            'quotes' => [[
                'quote_id' => 'Q-ADDR',
                'hubspot_quote_id' => 'quote_addr',
                'entity_actions' => [
                    'company' => [
                        'action' => 'create',
                        'entity_type' => 'company',
                        'entity' => [
                            'hubspot_id' => 'company_addr',
                        ],
                        'fields' => [
                            'razon_social_de_la_empresa' => 'Address Company',
                            'rfc' => 'ADD010101AA1',
                            'hs_object_id' => 'company_addr',
                        ],
                    ],
                    'contact' => [
                        'action' => 'create',
                        'entity_type' => 'contact',
                        'entity' => [
                            'hubspot_id' => 'contact_delivery',
                        ],
                        'fields' => [
                            'firstname' => 'Entrega',
                            'contact_type' => 'delivery',
                            'hs_object_id' => 'contact_delivery',
                        ],
                    ],
                    'products' => [],
                ],
                'raw' => [
                    'associations' => [
                        'contacts' => [
                            [
                                'id' => 'contact_delivery',
                                'properties' => [
                                    'firstname' => 'Entrega',
                                    'contact_type' => 'delivery',
                                    'hs_object_id' => 'contact_delivery',
                                ],
                            ],
                            [
                                'id' => 'contact_invoice',
                                'properties' => [
                                    'firstname' => 'Factura',
                                    'contact_type' => 'invoice',
                                    'hs_object_id' => 'contact_invoice',
                                ],
                            ],
                        ],
                    ],
                ],
            ]],
        ], $event, $record);

        $job->handle(
            app(EventLoggingService::class),
            app(SignedQuotesPipelineService::class),
            app(HubspotApiServiceRefactored::class)
        );

        $record->refresh();

        $this->assertSame('success', $record->status, json_encode($record->details, JSON_PRETTY_PRINT));
        Queue::assertPushed(
            ResolveAssociationsJob::class,
            fn (ResolveAssociationsJob $job): bool => count(data_get($job->payload, 'quotes.0.entity_results.contacts', [])) === 2
        );

        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts/contact_delivery'
            && data_get($request->data(), 'properties.odoo_id') === 777);

        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://api.hubapi.test/crm/v3/objects/contacts/contact_invoice'
            && data_get($request->data(), 'properties.odoo_id') === 778);
    }

    /**
     * @return array{0: Event, 1: Record}
     */
    private function signedQuoteContext(): array
    {
        $hubspot = Platform::query()->create([
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

        $odoo = Platform::query()->create([
            'name' => 'Odoo',
            'slug' => 'odoo',
            'type' => 'odoo',
            'active' => true,
            'credentials' => [
                'database' => 'directo_odoo',
                'username' => 'odoo_user',
                'password' => 'odoo_pass',
            ],
            'settings' => [
                'base_url' => 'https://odoo-directo.test',
                'protocol' => 'json_rpc',
            ],
        ]);

        $odooEvent = Event::query()->create([
            'platform_id' => $odoo->id,
            'name' => 'Create Destination Quote',
            'event_type_id' => 'invoice.recurring.created',
            'type' => 'job',
            'method_name' => 'createSaleSubscription',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $hubspot->id,
            'to_event_id' => $odooEvent->id,
            'name' => 'Fetch Signed Quotes',
            'event_type_id' => 'quotes.sending_data',
            'type' => 'schedule',
            'method_name' => 'getSignedQuotes',
            'active' => true,
        ]);

        foreach ([
            'company.razon_social_de_la_empresa' => 'name',
            'company.rfc' => 'vat',
            'company.hs_object_id' => 'x_studio_hubspot_id',
            'company.odoo_id' => 'partner_id',
            'contact.firstname' => 'name',
            'contact.email' => 'email',
            'contact.hs_object_id' => 'x_studio_hubspot_id',
            'contact.contact_type' => 'type',
        ] as $sourceKey => $targetKey) {
            $source = Property::query()->create([
                'platform_id' => $hubspot->id,
                'name' => $sourceKey,
                'key' => $sourceKey,
                'type' => 'string',
                'active' => true,
            ]);

            $target = Property::query()->create([
                'platform_id' => $odoo->id,
                'name' => $targetKey,
                'key' => $targetKey,
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

        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'quotes.sending_data',
            'status' => 'init',
            'payload' => [],
            'message' => 'Testing entity upsert',
        ]);

        return [$event, $record];
    }
}

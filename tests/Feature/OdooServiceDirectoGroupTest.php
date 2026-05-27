<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use App\Services\Odoo\OdooService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OdooServiceDirectoGroupTest extends TestCase
{
    public function test_res_partner_create_company_uses_platform_settings_and_returns_writeback_payload(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
                'defaults' => [
                    'company_id' => 1,
                ],
            ],
        ]);

        Http::fake(function ($request) {
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

            if ($model === 'res.company' && $method === 'search_read') {
                return Http::response(['result' => [['id' => 1]]], 200);
            }

            if ($model === 'res.partner' && $method === 'create') {
                return Http::response(['result' => 501], 200);
            }

            return Http::response(['result' => []], 200);
        });

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
        ]);

        $result = $service->resPartnerCreateCompany([
            'hubspot_object_id' => '12345',
            'name' => 'Directo Group',
            'email' => 'ops@directo.test',
            'vat' => 'DG010101AA1',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(501, $result['data']['id']);
        $this->assertSame('12345', $result['data']['output_payload']['id']);
        $this->assertSame(501, $result['data']['output_payload']['properties']['odoo_id']);
    }

    public function test_res_partner_create_or_update_contact_uses_contact_type_and_parent_company(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
            ],
        ]);

        Http::fake([
            'https://odoo-directo.test/jsonrpc' => Http::sequence()
                ->push(['result' => 7], 200)
                ->push(['result' => []], 200)
                ->push(['result' => 7], 200)
                ->push(['result' => 777], 200),
        ]);

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
        ]);

        $result = $service->resPartnerCreateOrUpdateContact([
            'hubspot_contact_id' => 'contact_123',
            'firstname' => 'Ana',
            'lastname' => 'Diaz',
            'email' => 'ana@example.test',
            'parent_id' => 501,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(777, $result['data']['id']);
        $this->assertSame('contact_123', $result['data']['output_payload']['id']);
        $this->assertSame(777, $result['data']['output_payload']['properties']['odoo_id']);
    }

    public function test_account_move_created_updated_fetches_invoice_and_subscription_payload(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
                'models' => [
                    'sale_subscription' => 'dp.sale.subscription',
                ],
            ],
        ]);

        Http::fake([
            'https://odoo-directo.test/jsonrpc' => Http::sequence()
                ->push(['result' => 7], 200)
                ->push(['result' => [[
                    'id' => 88,
                    'name' => 'INV/88',
                    'move_type' => 'out_invoice',
                    'invoice_origin' => 'SUB/10',
                    'payment_state' => 'not_paid',
                    'amount_total' => 123.45,
                ]]], 200)
                ->push(['result' => 7], 200)
                ->push(['result' => [[
                    'id' => 10,
                    'name' => 'SUB/10',
                    'state' => 'confirmed',
                    'x_studio_deal_id' => '999',
                ]]], 200),
        ]);

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
        ]);

        $result = $service->accountMoveCreatedUpdated(['id' => 88, 'stated' => 'posted_from_webhook']);

        $this->assertTrue($result['success']);
        $this->assertSame(88, $result['data']['output_payload']['id']);
        $this->assertSame('not_paid', $result['data']['output_payload']['status_in_payment']);
        $this->assertSame(88, $result['data']['output_payload']['invoice']['id']);
        $this->assertSame('posted_from_webhook', $result['data']['output_payload']['invoice']['stated']);
        $this->assertSame(10, $result['data']['output_payload']['saleOrder']['id']);
        $this->assertSame('999', $result['data']['output_payload']['deal_id']);
        $this->assertSame([], $result['data']['output_payload']['warnings']);
    }

    public function test_account_move_created_updated_ignores_non_customer_invoices(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
            ],
        ]);

        Http::fake([
            'https://odoo-directo.test/jsonrpc' => Http::sequence()
                ->push(['result' => 7], 200)
                ->push(['result' => [[
                    'id' => 89,
                    'name' => 'BILL/89',
                    'move_type' => 'in_invoice',
                ]]], 200),
        ]);

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
        ]);

        $result = $service->accountMoveCreatedUpdated(['id' => 89]);

        $this->assertTrue($result['success']);
        $this->assertSame('warning', $result['status']);
        $this->assertSame('unsupported_move_type', $result['data']['reason']);
        $this->assertSame([], $result['data']['output_payload']);
    }

    public function test_account_move_created_updated_warns_when_subscription_or_deal_is_missing(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
                'models' => [
                    'sale_subscription' => 'dp.sale.subscription',
                ],
            ],
        ]);

        Http::fake([
            'https://odoo-directo.test/jsonrpc' => Http::sequence()
                ->push(['result' => 7], 200)
                ->push(['result' => [[
                    'id' => 90,
                    'name' => 'INV/90',
                    'move_type' => 'out_invoice',
                    'invoice_origin' => 'SUB/MISSING',
                ]]], 200)
                ->push(['result' => 7], 200)
                ->push(['result' => []], 200),
        ]);

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
        ]);

        $result = $service->accountMoveCreatedUpdated(['id' => 90]);

        $this->assertTrue($result['success']);
        $this->assertSame('warning', $result['status']);
        $this->assertSame('sale_subscription_not_found', $result['data']['warnings'][0]['reason']);
        $this->assertSame('hubspot_deal_id_missing', $result['data']['warnings'][1]['reason']);
    }

    public function test_sync_create_products_reads_product_product_and_hydrates_list_prices(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
                'products' => [
                    'since' => '2026-05-22 00:00:00',
                    'limit' => 50,
                ],
                'catalogs' => [
                    'price_currency_properties' => [
                        'USD' => 'hs_price_usd',
                        'UYU' => 'hs_price_uyu',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'https://odoo-directo.test/jsonrpc' => Http::sequence()
                ->push(['result' => 7], 200)
                ->push(['result' => [[
                    'id' => 100,
                    'name' => 'Service Plan',
                    'product_tmpl_id' => [200, 'Service Plan'],
                    'default_code' => 'SP-100',
                    'product_subscription_pricing_ids' => [300],
                ]]], 200)
                ->push(['result' => 7], 200)
                ->push(['result' => [[
                    'pricelist_id' => [1, 'Public'],
                    'fixed_price' => 99.99,
                    'min_quantity' => 1,
                ], [
                    'pricelist_id' => [2, 'Uruguay'],
                    'fixed_price' => 120.50,
                    'min_quantity' => 1,
                ], [
                    'pricelist_id' => [3, 'No value'],
                    'fixed_price' => false,
                    'min_quantity' => 1,
                ]]], 200)
                ->push(['result' => 7], 200)
                ->push(['result' => [[
                    'id' => 1,
                    'name' => 'Public',
                    'currency_id' => [2, 'USD'],
                ], [
                    'id' => 2,
                    'name' => 'Uruguay',
                    'currency_id' => [3, 'UYU'],
                ], [
                    'id' => 3,
                    'name' => 'No value',
                    'currency_id' => [4, 'EUR'],
                ]]], 200)
                ->push(['result' => 7], 200)
                ->push(['result' => [[
                    'id' => 300,
                    'name' => 'Monthly',
                    'price' => 99.99,
                    'plan_id' => [400, 'Monthly plan'],
                ]]], 200),
        ]);

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
        ]);

        $result = $service->syncCreateProducts();

        $this->assertTrue($result['success']);
        $this->assertSame('create', $result['data']['operation']);
        $this->assertSame(100, $result['data']['products'][0]['id']);
        $this->assertSame(99.99, $result['data']['products'][0]['list_prices'][0]['fixed_price']);
        $this->assertSame('USD', $result['data']['products'][0]['list_prices'][0]['currency_code']);
        $this->assertSame(99.99, $result['data']['products'][0]['hs_price_usd']);
        $this->assertSame(120.50, $result['data']['products'][0]['hs_price_uyu']);
        $this->assertSame('Monthly plan', $result['data']['products'][0]['product_subscription_pricing_ids']);
        $this->assertSame($result['data']['products'], $result['data']['output_payload']);
    }

    public function test_get_list_prices_accepts_odoo_many2one_template_id(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => ['adapter' => 'json_rpc'],
        ]);

        Http::fake([
            'https://odoo-directo.test/jsonrpc' => Http::sequence()
                ->push(['result' => 7], 200)
                ->push(['result' => [[
                    'pricelist_id' => [1, 'Public'],
                    'fixed_price' => 55,
                    'min_quantity' => 1,
                ]]], 200)
                ->push(['result' => 7], 200)
                ->push(['result' => [[
                    'id' => 1,
                    'name' => 'Public',
                    'currency_id' => [2, 'USD'],
                ]]], 200),
        ]);

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
        ]);

        $result = $service->getListPricesByProduct([
            'product_tmpl_id' => [200, 'Template'],
        ], null);

        $this->assertTrue($result['success']);
        $this->assertSame(55, $result['data']['list_prices'][0]['fixed_price']);
        $this->assertSame('USD', $result['data']['list_prices'][0]['currency_code']);
    }

    public function test_sale_order_canceled_resolves_archived_quote_by_hubspot_quote_id(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
                'sale_order_cancel' => [
                    'search_fields' => [
                        'quote_id' => ['x_studio_quote_id'],
                    ],
                ],
            ],
        ]);

        Http::fake([
            'https://odoo-directo.test/jsonrpc' => Http::sequence()
                ->push(['result' => 7], 200)
                ->push(['result' => [[
                    'id' => 450,
                    'name' => 'SO450',
                ]]], 200)
                ->push(['result' => 7], 200)
                ->push(['result' => true], 200),
        ]);

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
        ]);

        $result = $service->saleOrderCanceled([
            'quotes' => [[
                'id' => 'hs_quote_123',
                'properties' => [
                    'hs_quote_number' => 'DG-001',
                ],
            ]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['canceled_count']);
        $this->assertSame(450, $result['data']['canceled'][0]['sale_order_id']);
    }

    public function test_sale_subscription_canceled_resolves_archived_quote_by_hubspot_quote_id(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
                'models' => [
                    'sale_subscription' => 'dp.sale.subscription',
                ],
                'sale_subscription_cancel' => [
                    'search_fields' => [
                        'quote_id' => ['x_studio_quote_id'],
                    ],
                ],
            ],
        ]);

        Http::fake([
            'https://odoo-directo.test/jsonrpc' => Http::sequence()
                ->push(['result' => 7], 200)
                ->push(['result' => [[
                    'id' => 256,
                    'name' => 'SUB256',
                    'state' => 'confirmed',
                    'x_studio_quote_id' => 'hs_quote_123',
                ]]], 200)
                ->push(['result' => 7], 200)
                ->push(['result' => true], 200),
        ]);

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
        ]);

        $result = $service->saleSubscriptionCanceled([
            'quotes' => [[
                'id' => 'hs_quote_123',
                'properties' => [
                    'hs_quote_number' => 'DG-001',
                ],
            ]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['canceled_count']);
        $this->assertSame(256, $result['data']['canceled'][0]['subscription_id']);
        $this->assertSame('hs_quote_123', $result['data']['output_payload'][0]['id']);
        $this->assertSame('cancelled', $result['data']['output_payload'][0]['operation']);
        $this->assertSame('cancelled', $result['data']['output_payload'][0]['properties']['sync_status_odoo']);
        $this->assertSame(256, $result['data']['output_payload'][0]['destination_response']['data']['id']);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://odoo-directo.test/jsonrpc') {
                return false;
            }

            $args = $request->data()['params']['args'] ?? [];

            return ($args[3] ?? null) === 'dp.sale.subscription'
                && ($args[4] ?? null) === 'write'
                && ($args[5][0] ?? null) === [256]
                && ($args[5][1]['state'] ?? null) === 'cancelled';
        });
    }

    public function test_create_sale_subscription_accepts_processed_quotes_batch(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
                'models' => [
                    'sale_subscription' => 'dp.sale.subscription',
                ],
            ],
        ]);

        Http::fake([
            'https://odoo-directo.test/jsonrpc' => Http::sequence()
                ->push(['result' => 7], 200)
                ->push(['result' => []], 200)
                ->push(['result' => 7], 200)
                ->push(['result' => 901], 200),
        ]);

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
        ]);

        $result = $service->createSaleSubscription([
            'quotes' => [[
                'quote_id' => 'Q-901',
                'hubspot_quote_id' => 'HSQ-901',
                'raw' => [
                    'associations' => [
                        'deals' => [
                            ['id' => 'DEAL-901'],
                        ],
                    ],
                ],
                'entity_results' => [
                    'company' => [
                        'target_id' => 55,
                    ],
                    'products' => [[
                        'target_id' => 77,
                        'fields' => [
                            'name' => 'Plan',
                            'quantity' => 1,
                            'price' => 250,
                        ],
                    ]],
                ],
            ]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['created_count']);
        $this->assertSame(901, $result['data']['created'][0]['id']);
        $this->assertSame('DEAL-901', $result['data']['output_payload'][0]['deal_id']);
    }

    public function test_create_sale_subscription_sends_dp_subscription_fields_and_address_partners(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
                'models' => [
                    'sale_subscription' => 'dp.sale.subscription',
                ],
                'defaults' => [
                    'company_id' => 1,
                ],
                'catalogs' => [
                    'taxes' => [
                        '16' => 2,
                    ],
                    'uom' => [
                        'Hora(s)' => 5,
                    ],
                ],
            ],
        ]);
        $createdPayload = null;

        Http::fake(function ($request) use (&$createdPayload) {
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

            if ($model === 'res.company' && $method === 'search_read') {
                return Http::response(['result' => [['id' => 1]]], 200);
            }

            if ($model === 'dp.sale.subscription' && $method === 'create') {
                $createdPayload = $args[5][0] ?? null;

                return Http::response(['result' => 901], 200);
            }

            return Http::response(['result' => []], 200);
        });

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
        ]);

        $result = $service->createSaleSubscription([
            'quotes' => [[
                'quote_id' => 'Q-901',
                'hubspot_quote_id' => 'HSQ-901',
                'entity_results' => [
                    'company' => [
                        'target_id' => 55,
                    ],
                    'contacts' => [
                        [
                            'target_id' => 71,
                            'fields' => [
                                'type' => 'invoice',
                            ],
                        ],
                        [
                            'target_id' => 72,
                            'fields' => [
                                'type' => 'delivery',
                            ],
                        ],
                    ],
                    'products' => [[
                        'target_id' => 77,
                        'fields' => [
                            'name' => 'Plan',
                            'quantity' => '2',
                            'price' => '250',
                            'hs_discount_percentage' => '30',
                            'hs_tax_rate' => '16.0000',
                            'unidad_de_medida' => 'Hora(s)',
                        ],
                    ]],
                ],
            ]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(55, $createdPayload['partner_id']);
        $this->assertSame(71, $createdPayload['partner_invoice_id']);
        $this->assertSame(72, $createdPayload['partner_shipping_id']);
        $this->assertSame(1, $createdPayload['company_id']);
        $this->assertArrayNotHasKey('name', $createdPayload);
        $this->assertSame('HSQ-901', $createdPayload['x_studio_quote_id']);

        $line = $createdPayload['order_line_ids'][0][2];
        $this->assertArrayNotHasKey('product_id', $line);
        $this->assertSame(77, $line['product_template_id']);
        $this->assertSame(2.0, $line['quantity']);
        $this->assertSame(250.0, $line['price_unit']);
        $this->assertSame(30.0, $line['discount']);
        $this->assertSame(5, $line['product_uom']);
        $this->assertSame([[6, 0, [2]]], $line['tax_id']);
    }

    public function test_create_sale_subscription_maps_quote_terms_to_odoo_note(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
                'models' => [
                    'sale_subscription' => 'dp.sale.subscription',
                ],
            ],
        ]);
        $event = $this->saleSubscriptionEventWithRelationships([
            $this->relationship('hs_terms', 'note'),
        ]);
        $createdPayload = null;

        Http::fake(function ($request) use (&$createdPayload) {
            if ($request->url() !== 'https://odoo-directo.test/jsonrpc') {
                return Http::response([], 404);
            }

            $params = $request->data()['params'] ?? [];
            if (($params['service'] ?? null) === 'common') {
                return Http::response(['result' => 7], 200);
            }

            $args = $params['args'] ?? [];
            if (($args[3] ?? null) === 'dp.sale.subscription' && ($args[4] ?? null) === 'create') {
                $createdPayload = $args[5][0] ?? null;

                return Http::response(['result' => 904], 200);
            }

            return Http::response(['result' => []], 200);
        });

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
            'event' => $event,
        ]);

        $result = $service->createSaleSubscription([
            'quotes' => [[
                'quote_id' => 'Q-904',
                'hubspot_quote_id' => 'HSQ-904',
                'raw' => [
                    'properties' => [
                        'hs_terms' => 'Pago a 30 dias.',
                    ],
                ],
                'entity_results' => [
                    'company' => [
                        'target_id' => 55,
                    ],
                ],
            ]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('Pago a 30 dias.', $createdPayload['note']);
        $this->assertSame('HSQ-904', $createdPayload['x_studio_quote_id']);
    }

    public function test_create_sale_subscription_can_build_note_from_mapping_meta_sources(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
                'models' => [
                    'sale_subscription' => 'dp.sale.subscription',
                ],
            ],
        ]);
        $event = $this->saleSubscriptionEventWithRelationships([
            $this->relationship('hs_terms', 'note', [
                'sources' => [
                    ['label' => 'Terminos', 'path' => 'hs_terms', 'transform' => 'html_to_text'],
                    ['label' => 'Nombre Comercial', 'path' => 'raw.associations.deals.0.properties.nombre_comercial_del_cliente'],
                    ['label' => 'Email Usuario', 'path' => 'raw.associations.deals.0.owner.email'],
                    ['template' => 'Deal HubSpot: {raw.associations.deals.0.id}'],
                ],
                'separator' => null,
            ]),
        ]);
        $createdPayload = null;

        Http::fake(function ($request) use (&$createdPayload) {
            if ($request->url() !== 'https://odoo-directo.test/jsonrpc') {
                return Http::response([], 404);
            }

            $params = $request->data()['params'] ?? [];
            if (($params['service'] ?? null) === 'common') {
                return Http::response(['result' => 7], 200);
            }

            $args = $params['args'] ?? [];
            if (($args[3] ?? null) === 'dp.sale.subscription' && ($args[4] ?? null) === 'create') {
                $createdPayload = $args[5][0] ?? null;

                return Http::response(['result' => 905], 200);
            }

            return Http::response(['result' => []], 200);
        });

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
            'event' => $event,
        ]);

        $result = $service->createSaleSubscription([
            'quotes' => [[
                'quote_id' => 'Q-905',
                'hubspot_quote_id' => 'HSQ-905',
                'raw' => [
                    'properties' => [
                        'hs_terms' => '<div><p>Nota demo 27/11/2025 12:00pm</p><p>Nota demo 25/05/2026 11:5am</p></div>',
                    ],
                    'associations' => [
                        'deals' => [
                            [
                                'id' => 'DEAL-905',
                                'owner' => [
                                    'email' => 'owner@example.test',
                                ],
                                'properties' => [
                                    'nombre_comercial_del_cliente' => 'Test demo comercial',
                                ],
                            ],
                        ],
                    ],
                ],
                'entity_results' => [
                    'company' => [
                        'target_id' => 55,
                    ],
                ],
            ]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(
            "Terminos: Nota demo 27/11/2025 12:00pm\nNota demo 25/05/2026 11:5am\nNombre Comercial: Test demo comercial\nEmail Usuario: owner@example.test\nDeal HubSpot: DEAL-905",
            $createdPayload['note']
        );
    }

    public function test_create_sale_subscription_allows_custom_header_fields_from_mapping_targets(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
                'models' => [
                    'sale_subscription' => 'dp.sale.subscription',
                ],
            ],
        ]);
        $event = $this->saleSubscriptionEventWithRelationships([
            $this->relationship('hs_quote_number', 'x_studio_folio'),
            $this->relationship('deal.hs_object_id', 'x_studio_deal_id', [], 'raw.associations.deals.0.id'),
        ]);
        $createdPayload = null;

        Http::fake(function ($request) use (&$createdPayload) {
            if ($request->url() !== 'https://odoo-directo.test/jsonrpc') {
                return Http::response([], 404);
            }

            $params = $request->data()['params'] ?? [];
            if (($params['service'] ?? null) === 'common') {
                return Http::response(['result' => 7], 200);
            }

            $args = $params['args'] ?? [];
            if (($args[3] ?? null) === 'dp.sale.subscription' && ($args[4] ?? null) === 'create') {
                $createdPayload = $args[5][0] ?? null;

                return Http::response(['result' => 906], 200);
            }

            return Http::response(['result' => []], 200);
        });

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
            'event' => $event,
        ]);

        $result = $service->createSaleSubscription([
            'quotes' => [[
                'quote_id' => 'Q-906',
                'hubspot_quote_id' => 'HSQ-906',
                'raw' => [
                    'properties' => [
                        'hs_quote_number' => '20260525-175704955',
                    ],
                    'associations' => [
                        'deals' => [
                            ['id' => '70660441326'],
                        ],
                    ],
                ],
                'entity_results' => [
                    'company' => [
                        'target_id' => 55,
                    ],
                ],
            ]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('20260525-175704955', $createdPayload['x_studio_folio']);
        $this->assertSame('70660441326', $createdPayload['x_studio_deal_id']);
    }

    public function test_create_sale_subscription_resolves_quote_currency_to_odoo_currency_id(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
                'models' => [
                    'sale_subscription' => 'dp.sale.subscription',
                ],
            ],
        ]);
        $event = $this->saleSubscriptionEventWithRelationships([
            $this->relationship('hs_currency', 'currency_id'),
        ]);
        $createdPayload = null;

        Http::fake(function ($request) use (&$createdPayload) {
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

            if ($model === 'dp.sale.subscription' && $method === 'search_read') {
                return Http::response(['result' => []], 200);
            }

            if ($model === 'res.currency' && $method === 'search_read') {
                $this->assertSame([['name', '=', 'USD']], $args[5][0] ?? null);

                return Http::response(['result' => [['id' => 2, 'name' => 'USD']]], 200);
            }

            if ($model === 'dp.sale.subscription' && $method === 'create') {
                $createdPayload = $args[5][0] ?? null;

                return Http::response(['result' => 907], 200);
            }

            return Http::response(['result' => []], 200);
        });

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
            'event' => $event,
        ]);

        $result = $service->createSaleSubscription([
            'quotes' => [[
                'quote_id' => 'Q-907',
                'hubspot_quote_id' => 'HSQ-907',
                'raw' => [
                    'properties' => [
                        'hs_currency' => 'USD',
                    ],
                ],
                'entity_results' => [
                    'company' => [
                        'target_id' => 55,
                    ],
                ],
            ]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $createdPayload['currency_id']);
    }

    public function test_create_sale_subscription_falls_back_address_partners_to_company_partner(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
                'models' => [
                    'sale_subscription' => 'dp.sale.subscription',
                ],
            ],
        ]);
        $createdPayload = null;

        Http::fake(function ($request) use (&$createdPayload) {
            if ($request->url() !== 'https://odoo-directo.test/jsonrpc') {
                return Http::response([], 404);
            }

            $params = $request->data()['params'] ?? [];
            if (($params['service'] ?? null) === 'common') {
                return Http::response(['result' => 7], 200);
            }

            $args = $params['args'] ?? [];
            if (($args[3] ?? null) === 'dp.sale.subscription' && ($args[4] ?? null) === 'create') {
                $createdPayload = $args[5][0] ?? null;

                return Http::response(['result' => 902], 200);
            }

            return Http::response(['result' => []], 200);
        });

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
        ]);

        $result = $service->createSaleSubscription([
            'quotes' => [[
                'quote_id' => 'Q-902',
                'hubspot_quote_id' => 'HSQ-902',
                'entity_results' => [
                    'company' => [
                        'target_id' => 55,
                    ],
                ],
            ]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(55, $createdPayload['partner_id']);
        $this->assertSame(55, $createdPayload['partner_invoice_id']);
        $this->assertSame(55, $createdPayload['partner_shipping_id']);
    }

    public function test_create_sale_subscription_uses_created_company_partner_id_over_mapped_partner_id(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
                'models' => [
                    'sale_subscription' => 'dp.sale.subscription',
                ],
            ],
        ]);
        $createdPayload = null;

        Http::fake(function ($request) use (&$createdPayload) {
            if ($request->url() !== 'https://odoo-directo.test/jsonrpc') {
                return Http::response([], 404);
            }

            $params = $request->data()['params'] ?? [];
            if (($params['service'] ?? null) === 'common') {
                return Http::response(['result' => 7], 200);
            }

            $args = $params['args'] ?? [];
            if (($args[3] ?? null) === 'dp.sale.subscription' && ($args[4] ?? null) === 'create') {
                $createdPayload = $args[5][0] ?? null;

                return Http::response(['result' => 903], 200);
            }

            return Http::response(['result' => []], 200);
        });

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
        ]);

        $result = $service->createSaleSubscription([
            'general' => [
                'name' => 'Q-903',
                'partner_id' => 999,
            ],
            'company' => [
                'partner_id' => 55,
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(55, $createdPayload['partner_id']);
        $this->assertSame(55, $createdPayload['partner_invoice_id']);
        $this->assertSame(55, $createdPayload['partner_shipping_id']);
    }

    public function test_create_sale_subscription_fails_before_odoo_when_company_partner_id_is_missing(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
                'models' => [
                    'sale_subscription' => 'dp.sale.subscription',
                ],
            ],
        ]);

        Http::fake();

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
        ]);

        $result = $service->createSaleSubscription([
            'general' => [
                'name' => 'Q-MISSING',
                'partner_id' => 999,
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('missing_company_partner_id', $result['data']['reason']);
        Http::assertNothingSent();
    }

    public function test_create_sale_subscription_finishes_when_quote_already_exists(): void
    {
        $platform = $this->jsonRpcPlatform([
            'odoo' => [
                'adapter' => 'json_rpc',
                'models' => [
                    'sale_subscription' => 'dp.sale.subscription',
                ],
            ],
        ]);

        Http::fake([
            'https://odoo-directo.test/jsonrpc' => Http::sequence()
                ->push(['result' => 7], 200)
                ->push(['result' => [[
                    'id' => 901,
                    'name' => 'Q-901',
                    'x_studio_quote_id' => 'HSQ-901',
                ]]], 200),
        ]);

        $service = app()->make(OdooService::class, [
            'platform' => $platform,
        ]);

        $result = $service->createSaleSubscription([
            'quotes' => [[
                'quote_id' => 'Q-901',
                'hubspot_quote_id' => 'HSQ-901',
                'entity_results' => [
                    'company' => [
                        'target_id' => 55,
                    ],
                ],
            ]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['created_count']);
        $this->assertSame('already_exists', $result['data']['created'][0]['operation']);
        $this->assertSame(0, $result['data']['error_count']);
    }

    private function jsonRpcPlatform(array $settings = []): Platform
    {
        return new Platform([
            'name' => 'Odoo directoGroup',
            'slug' => 'odoo-directogroup',
            'type' => 'odoo',
            'credentials' => [
                'database' => 'directo_odoo',
                'username' => 'odoo_user',
                'password' => 'odoo_pass',
            ],
            'settings' => array_replace_recursive([
                'url' => 'https://odoo-directo.test',
            ], $settings),
        ]);
    }

    /**
     * @param  array<int, PropertyRelationship>  $relationships
     */
    private function saleSubscriptionEventWithRelationships(array $relationships): Event
    {
        $event = new Event([
            'name' => 'Create Destination Quote/Subscription',
            'event_type_id' => 'quotes.sending_data',
            'method_name' => 'createSaleSubscription',
            'active' => true,
        ]);
        $event->setRelation('propertyRelationships', new Collection($relationships));

        return $event;
    }

    private function relationship(string $sourceKey, string $targetKey, array $meta = [], ?string $mappingKey = null): PropertyRelationship
    {
        $relationship = new PropertyRelationship([
            'mapping_key' => $mappingKey ?? $sourceKey,
            'active' => true,
            'meta' => $meta,
        ]);
        $relationship->setRelation('property', new Property([
            'key' => $sourceKey,
            'type' => 'string',
        ]));
        $relationship->setRelation('relatedProperty', new Property([
            'key' => $targetKey,
            'type' => 'string',
        ]));

        return $relationship;
    }
}

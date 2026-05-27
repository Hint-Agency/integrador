<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use App\Services\Hubspot\HubspotService;
use App\Services\Hubspot\ProductCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class HubspotInvoiceObjectSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_invoice_object_is_created_and_associated_to_deal_when_missing(): void
    {
        $platform = Platform::query()->create([
            'name' => 'HubSpot directo',
            'slug' => 'hubspot-directo-invoices',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Create/Update Source Invoice Object',
            'event_type_id' => 'invoice.created',
            'method_name' => 'createOrUpdateInvoiceObject',
            'type' => 'webhook',
            'active' => true,
            'meta' => [
                'invoice_object_type' => 'p_invoices',
                'invoice_match_property' => 'odoo_id',
                'invoice_match_fallback_properties' => ['name'],
                'invoice_to_deal_association_type_id' => 123,
                'invoice_property_map' => [
                    'estado_en_odoo' => 'saleOrder.state',
                ],
            ],
        ]);

        $odooPlatform = Platform::query()->create([
            'name' => 'Odoo directo',
            'slug' => 'odoo-directo-invoices',
            'type' => 'odoo',
            'active' => true,
        ]);
        $sourceNote = Property::query()->create([
            'platform_id' => $odooPlatform->id,
            'name' => 'Subscription Note',
            'key' => 'saleOrder.note',
            'type' => 'string',
            'active' => true,
        ]);
        $sourceName = Property::query()->create([
            'platform_id' => $odooPlatform->id,
            'name' => 'Invoice Name',
            'key' => 'invoice.name',
            'type' => 'string',
            'active' => true,
        ]);
        $targetNote = Property::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Invoice Note',
            'key' => 'nota_factura',
            'type' => 'string',
            'active' => true,
        ]);
        $targetName = Property::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Name',
            'key' => 'name',
            'type' => 'string',
            'active' => true,
        ]);
        $sourceDeal = Property::query()->create([
            'platform_id' => $odooPlatform->id,
            'name' => 'Deal ID',
            'key' => 'saleOrder.x_studio_deal_id',
            'type' => 'string',
            'active' => true,
        ]);
        $targetDeal = Property::query()->create([
            'platform_id' => $platform->id,
            'name' => 'HubSpot Object ID',
            'key' => 'hs_object_id',
            'type' => 'string',
            'active' => true,
        ]);
        PropertyRelationship::query()->create([
            'event_id' => $event->id,
            'property_id' => $sourceNote->id,
            'related_property_id' => $targetNote->id,
            'active' => true,
        ]);
        PropertyRelationship::query()->create([
            'event_id' => $event->id,
            'property_id' => $sourceName->id,
            'related_property_id' => $targetName->id,
            'active' => true,
        ]);
        PropertyRelationship::query()->create([
            'event_id' => $event->id,
            'property_id' => $sourceDeal->id,
            'related_property_id' => $targetDeal->id,
            'active' => true,
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('p_invoices', 'odoo_id', '88', Mockery::type('array'))
            ->andReturn([
                'success' => true,
                'data' => ['results' => []],
            ]);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('p_invoices', 'name', 'INV/88', Mockery::type('array'))
            ->andReturn([
                'success' => true,
                'data' => ['results' => []],
            ]);
        $hubspotApi->shouldReceive('createObject')
            ->once()
            ->with('p_invoices', Mockery::on(fn (array $properties): bool => ($properties['odoo_id'] ?? null) === '88'
                && ($properties['name'] ?? null) === 'INV/88'
                && ! array_key_exists('invoice_origin', $properties)
                && ! array_key_exists('amount_total', $properties)
                && ($properties['estado_en_odoo'] ?? null) === 'confirmed'
                && ($properties['nota_factura'] ?? null) === 'Factura generada desde suscripcion'
                && ! array_key_exists('hs_object_id', $properties)
                && ! array_key_exists('x_studio_datetime_field_R0ZUK', $properties)))
            ->andReturn([
                'success' => true,
                'data' => ['id' => 'inv_100'],
            ]);
        $hubspotApi->shouldReceive('associateObjects')
            ->once()
            ->with('p_invoices', 'inv_100', 'deals', 'deal_999', 123)
            ->andReturn([
                'success' => true,
                'status_code' => 200,
                'data' => [],
            ]);

        $productCache = Mockery::mock(ProductCacheService::class);

        $service = new HubspotService($platform, $event, null, $hubspotApi, $productCache);
        $result = $service->createOrUpdateInvoiceObject([
            'invoice' => [
                'id' => 88,
                'name' => 'INV/88',
                'invoice_origin' => 'SUB/10',
                'payment_state' => 'not_paid',
                'amount_total' => 123.45,
            ],
            'saleOrder' => [
                'state' => 'confirmed',
                'note' => 'Factura generada desde suscripcion',
                'x_studio_deal_id' => 'deal_999',
            ],
            'x_studio_datetime_field_R0ZUK' => false,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('created', $result['data']['operation']);
        $this->assertSame('inv_100', $result['data']['object_id']);
        $this->assertSame('deal_999', $result['data']['deal_id']);
    }

    public function test_invoice_object_falls_back_to_name_match_when_odoo_id_is_missing(): void
    {
        $platform = Platform::query()->create([
            'name' => 'HubSpot directo',
            'slug' => 'hubspot-directo-invoices',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Create/Update Source Invoice Object',
            'event_type_id' => 'invoice.created',
            'method_name' => 'createOrUpdateInvoiceObject',
            'type' => 'webhook',
            'active' => true,
            'meta' => [
                'invoice_object_type' => 'p143559608_facturas_erp',
                'invoice_match_property' => 'odoo_id',
                'invoice_match_fallback_properties' => ['name'],
                'invoice_to_deal_association_type_id' => 39,
                'invoice_property_map' => [
                    'name' => 'name',
                    'estado_en_odoo' => 'estado_en_odoo',
                ],
            ],
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('p143559608_facturas_erp', 'odoo_id', '88', Mockery::type('array'))
            ->andReturn([
                'success' => true,
                'data' => ['results' => []],
            ]);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('p143559608_facturas_erp', 'name', 'INV/88', Mockery::type('array'))
            ->andReturn([
                'success' => true,
                'data' => ['results' => [['id' => 'existing_invoice']]],
            ]);
        $hubspotApi->shouldReceive('updateObject')
            ->once()
            ->with('p143559608_facturas_erp', 'existing_invoice', Mockery::on(fn (array $properties): bool => ($properties['odoo_id'] ?? null) === '88'
                && ($properties['estado_en_odoo'] ?? null) === 'paid'))
            ->andReturn([
                'success' => true,
                'data' => ['id' => 'existing_invoice'],
            ]);
        $hubspotApi->shouldReceive('associateObjects')
            ->once()
            ->with('p143559608_facturas_erp', 'existing_invoice', 'deals', 'deal_999', 39)
            ->andReturn([
                'success' => true,
                'status_code' => 200,
                'data' => [],
            ]);

        $productCache = Mockery::mock(ProductCacheService::class);

        $service = new HubspotService($platform, $event, null, $hubspotApi, $productCache);
        $result = $service->createOrUpdateInvoiceObject([
            'invoice' => [
                'id' => 88,
                'name' => 'INV/88',
                'invoice_origin' => 'SUB/10',
            ],
            'name' => 'INV/88',
            'odoo_id' => '88',
            'estado_en_odoo' => 'paid',
            'hs_object_id' => 'deal_999',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('updated', $result['data']['operation']);
        $this->assertSame('existing_invoice', $result['data']['object_id']);
        $this->assertSame('name', $result['data']['match']['property']);
    }
}

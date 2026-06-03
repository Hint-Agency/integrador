<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use App\Models\Record;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use App\Services\Hubspot\HubspotService;
use App\Services\Hubspot\ProductCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class HubspotProductUpdateResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_product_updated_event_can_resolve_hubspot_id_by_identificador_db_before_updating(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Hubspot corripio',
            'slug' => 'hubspot-corripio',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Actualización de productos',
            'event_type_id' => 'product.updated',
            'type' => 'webhook',
            'active' => true,
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('products', 'identificador_db', 'MC-000037512', ['identificador_db'])
            ->andReturn([
                'success' => true,
                'data' => [
                    'results' => [
                        ['id' => '2001'],
                    ],
                ],
            ]);
        $hubspotApi->shouldReceive('updateProduct')
            ->once()
            ->with('2001', [
                'identificador_db' => 'MC-000037512',
                'name' => 'Producto Corripio',
            ])
            ->andReturn([
                'success' => true,
                'data' => ['id' => '2001'],
            ]);

        $productCache = Mockery::mock(ProductCacheService::class);
        $productCache->shouldReceive('preload')->once();

        $service = new HubspotService($platform, $event, null, $hubspotApi, $productCache);
        $result = $service->updateProducts([
            [
                'identificador_db' => 'MC-000037512',
                'name' => 'Producto Corripio',
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, data_get($result, 'data.updated_count'));
        $this->assertSame([], data_get($result, 'data.output_payload'));
    }

    public function test_product_updated_event_prepares_not_found_products_for_creation_when_next_event_exists(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Hubspot corripio',
            'slug' => 'hubspot-corripio',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        $createEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Creación de productos',
            'event_type_id' => 'product.created',
            'type' => 'webhook',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Actualización de productos',
            'event_type_id' => 'product.updated',
            'type' => 'webhook',
            'to_event_id' => $createEvent->id,
            'active' => true,
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('products', 'identificador_db', 'MC-000099999', ['identificador_db'])
            ->andReturn([
                'success' => true,
                'data' => [
                    'results' => [],
                ],
            ]);
        $hubspotApi->shouldNotReceive('updateProduct');

        $productCache = Mockery::mock(ProductCacheService::class);
        $productCache->shouldNotReceive('preload');

        $service = new HubspotService($platform, $event, null, $hubspotApi, $productCache);
        $result = $service->updateProducts([
            [
                'identificador_db' => 'MC-000099999',
                'name' => 'Producto nuevo',
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertNull($result['status'] ?? null);
        $this->assertSame(0, data_get($result, 'data.updated_count'));
        $this->assertSame(1, data_get($result, 'data.not_found_count'));
        $this->assertSame('MC-000099999', data_get($result, 'data.output_payload.0.identificador_db'));
    }

    public function test_product_updated_event_uses_creation_fallback_when_hubspot_search_fails_and_next_event_exists(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Hubspot directo',
            'slug' => 'hubspot-directo-search-fallback',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        $createEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Creación de productos',
            'event_type_id' => 'product.created',
            'type' => 'webhook',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Actualización de productos',
            'event_type_id' => 'product.updated',
            'type' => 'webhook',
            'to_event_id' => $createEvent->id,
            'active' => true,
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('products', 'odoo_id', '2660', ['odoo_id'])
            ->andReturn([
                'success' => false,
                'error' => [
                    'status' => 'error',
                    'message' => 'There was a problem with the request.',
                    'correlationId' => '019e853e-54e4-7d07-948e-0c9389edc170',
                ],
            ]);
        $hubspotApi->shouldNotReceive('updateProduct');

        $productCache = Mockery::mock(ProductCacheService::class);
        $productCache->shouldNotReceive('preload');

        $service = new HubspotService($platform, $event, null, $hubspotApi, $productCache);
        $result = $service->updateProducts([
            [
                'odoo_id' => '2660',
                'hs_sku' => '0107260427',
                'sku' => '0107260427',
                'name' => 'Producto nuevo',
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('warning', $result['status'] ?? null);
        $this->assertSame(0, data_get($result, 'data.updated_count'));
        $this->assertSame(0, data_get($result, 'data.error_count'));
        $this->assertSame(1, data_get($result, 'data.not_found_count'));
        $this->assertSame('product_search_failed_create_fallback', data_get($result, 'data.warning_reason'));
        $this->assertSame('2660', data_get($result, 'data.output_payload.0.odoo_id'));
        $this->assertSame(
            'hubspot_product_search_failed_create_fallback',
            data_get($result, 'data.output_payload.0._resolution_warning.reason')
        );
        $this->assertSame(
            '019e853e-54e4-7d07-948e-0c9389edc170',
            data_get($result, 'data.fallback_warnings.0.details.correlationId')
        );
    }

    public function test_product_updated_event_can_resolve_hubspot_id_by_odoo_id(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Hubspot directo',
            'slug' => 'hubspot-directo',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Actualización de productos',
            'event_type_id' => 'product.updated',
            'type' => 'webhook',
            'active' => true,
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('products', 'odoo_id', '100', ['odoo_id'])
            ->andReturn([
                'success' => true,
                'data' => [
                    'results' => [
                        ['id' => '3001'],
                    ],
                ],
            ]);
        $hubspotApi->shouldReceive('updateProduct')
            ->once()
            ->with('3001', [
                'name' => 'Plan mensual',
                'default_code' => 'PLAN-100',
                'sku' => 'PLAN-100',
                'odoo_id' => '100',
            ])
            ->andReturn([
                'success' => true,
                'data' => ['id' => '3001'],
            ]);

        $productCache = Mockery::mock(ProductCacheService::class);
        $productCache->shouldReceive('preload')->once();

        $service = new HubspotService($platform, $event, null, $hubspotApi, $productCache);
        $result = $service->updateProducts([
            [
                'id' => 100,
                'default_code' => 'PLAN-100',
                'name' => 'Plan mensual',
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, data_get($result, 'data.updated_count'));
    }

    public function test_product_updated_event_uses_odoo_template_id_and_hs_sku_for_odoo_payload(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Hubspot directo',
            'slug' => 'hubspot-directo-template',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Actualización de productos',
            'event_type_id' => 'product.updated',
            'type' => 'webhook',
            'active' => true,
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('products', 'odoo_id', '2390', ['odoo_id'])
            ->andReturn([
                'success' => true,
                'data' => [
                    'results' => [
                        ['id' => '3001'],
                    ],
                ],
            ]);
        $hubspotApi->shouldReceive('updateProduct')
            ->once()
            ->with('3001', Mockery::on(fn (array $properties): bool => ($properties['odoo_id'] ?? null) === '2390'
                && ($properties['odoo_product_id'] ?? null) === '2172'
                && ($properties['hs_sku'] ?? null) === 'RMPAYAO01'
                && ! array_key_exists('id', $properties)
                && ! array_key_exists('uom_id', $properties)
                && ! array_key_exists('categ_id', $properties)
                && ! array_key_exists('currency_id', $properties)
                && ! array_key_exists('product_tmpl_id', $properties)
                && ! array_key_exists('list_prices', $properties)
                && ! array_key_exists('product_subscription_pricing', $properties)))
            ->andReturn([
                'success' => true,
                'data' => ['id' => '3001'],
            ]);

        $productCache = Mockery::mock(ProductCacheService::class);
        $productCache->shouldReceive('preload')->once();

        $service = new HubspotService($platform, $event, null, $hubspotApi, $productCache);
        $result = $service->updateProducts([
            [
                'id' => 2172,
                'odoo_id' => 2172,
                'product_tmpl_id' => [2390, '[RMPAYAO01] Renta mensual'],
                'uom_id' => [20, 'Unidad de Servicio'],
                'categ_id' => [51, 'Licenciamiento AmbitOne'],
                'currency_id' => [34, 'MXN'],
                'list_prices' => [],
                'default_code' => 'RMPAYAO01',
                'hs_sku' => 'RMPAYAO01',
                'name' => 'Renta mensual',
                'product_subscription_pricing' => [
                    'id' => 854,
                    'name' => '1 Months',
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, data_get($result, 'data.updated_count'));
    }

    public function test_product_updated_event_writes_only_properties_mapped_by_upstream_event(): void
    {
        $hubspot = Platform::query()->create([
            'name' => 'Hubspot directo',
            'slug' => 'hubspot-directo-mapped',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        $odoo = Platform::query()->create([
            'name' => 'Odoo directo',
            'slug' => 'odoo-directo-mapped',
            'type' => 'odoo',
            'active' => true,
        ]);

        $sourceEvent = Event::query()->create([
            'platform_id' => $odoo->id,
            'name' => 'Sync Source Products',
            'event_type_id' => 'product.updated',
            'type' => 'schedule',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $hubspot->id,
            'name' => 'Update Destination Product',
            'event_type_id' => 'product.updated',
            'type' => 'webhook',
            'active' => true,
        ]);

        foreach ([
            ['source' => 'product_tmpl_id.0', 'target' => 'odoo_id', 'type' => 'string'],
            ['source' => 'name', 'target' => 'name', 'type' => 'string'],
            ['source' => 'default_code', 'target' => 'hs_sku', 'type' => 'string'],
            ['source' => 'list_price', 'target' => 'price', 'type' => 'float'],
            ['source' => 'hs_price_usd', 'target' => 'hs_price_usd', 'type' => 'float'],
            ['source' => 'hs_price_uyu', 'target' => 'hs_price_uyu', 'type' => 'float'],
            ['source' => 'uom_id.0', 'target' => 'unidad_de_medida', 'type' => 'string'],
        ] as $mapping) {
            $sourceProperty = Property::query()->create([
                'platform_id' => $odoo->id,
                'name' => $mapping['source'],
                'key' => $mapping['source'],
                'type' => $mapping['type'],
                'active' => true,
            ]);

            $targetProperty = Property::query()->create([
                'platform_id' => $hubspot->id,
                'name' => $mapping['target'],
                'key' => $mapping['target'],
                'type' => $mapping['type'],
                'active' => true,
            ]);

            PropertyRelationship::query()->create([
                'event_id' => $sourceEvent->id,
                'property_id' => $sourceProperty->id,
                'related_property_id' => $targetProperty->id,
                'mapping_key' => $mapping['source'],
                'active' => true,
            ]);
        }

        $sourceRecord = Record::query()->create([
            'event_id' => $sourceEvent->id,
            'event_type' => 'product.updated',
            'status' => 'success',
            'payload' => [],
            'message' => 'source',
        ]);

        $record = Record::query()->create([
            'event_id' => $event->id,
            'record_id' => $sourceRecord->id,
            'event_type' => 'product.updated',
            'status' => 'init',
            'payload' => [],
            'message' => 'update',
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('products', 'odoo_id', '2390', ['odoo_id'])
            ->andReturn([
                'success' => true,
                'data' => [
                    'results' => [
                        ['id' => '3001'],
                    ],
                ],
            ]);
        $hubspotApi->shouldReceive('updateProduct')
            ->once()
            ->with('3001', [
                'name' => 'Renta mensual',
                'price' => 300.0,
                'hs_sku' => 'RMPAYAO01',
                'odoo_id' => '2390',
                'unidad_de_medida' => 'Unidad de servicio',
                'hs_price_usd' => 99.99,
                'hs_price_uyu' => 120.5,
            ])
            ->andReturn([
                'success' => true,
                'data' => ['id' => '3001'],
            ]);

        $productCache = Mockery::mock(ProductCacheService::class);
        $productCache->shouldReceive('preload')->once();

        $service = new HubspotService($hubspot, $event, $record, $hubspotApi, $productCache);
        $result = $service->updateProducts([
            [
                'id' => 2172,
                'odoo_id' => '2390',
                'product_tmpl_id' => [2390, '[RMPAYAO01] Renta mensual'],
                'default_code' => 'RMPAYAO01',
                'hs_sku' => 'RMPAYAO01',
                'name' => 'Renta mensual',
                'list_price' => 300,
                'price' => 300.0,
                'hs_price_usd' => 99.99,
                'hs_price_uyu' => 120.5,
                'unidad_de_medida' => 'Unidad de servicio',
                'write_date' => '2026-05-26 13:08:50',
                'lst_price' => 300,
                'uom_id' => [20, 'Unidad de Servicio'],
                'currency_id' => [34, 'MXN'],
                'product_subscription_pricing_ids' => 'Upgraded Evento',
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, data_get($result, 'data.updated_count'));
    }

    public function test_product_updated_event_can_match_by_hs_sku_when_template_id_is_not_found(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Hubspot directo',
            'slug' => 'hubspot-directo-hs-sku',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Actualización de productos',
            'event_type_id' => 'product.updated',
            'type' => 'webhook',
            'active' => true,
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('products', 'odoo_id', '2390', ['odoo_id'])
            ->andReturn([
                'success' => true,
                'data' => [
                    'results' => [],
                ],
            ]);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('products', 'hs_sku', 'RMPAYAO01', ['hs_sku'])
            ->andReturn([
                'success' => true,
                'data' => [
                    'results' => [
                        ['id' => '3001'],
                    ],
                ],
            ]);
        $hubspotApi->shouldReceive('updateProduct')
            ->once()
            ->with('3001', Mockery::type('array'))
            ->andReturn([
                'success' => true,
                'data' => ['id' => '3001'],
            ]);

        $productCache = Mockery::mock(ProductCacheService::class);
        $productCache->shouldReceive('preload')->once();

        $service = new HubspotService($platform, $event, null, $hubspotApi, $productCache);
        $result = $service->updateProducts([
            [
                'id' => 2172,
                'product_tmpl_id' => [2390, '[RMPAYAO01] Renta mensual'],
                'hs_sku' => 'RMPAYAO01',
                'name' => 'Renta mensual',
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, data_get($result, 'data.updated_count'));
    }

    public function test_product_updated_event_can_match_by_default_code_as_sku(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Hubspot directo',
            'slug' => 'hubspot-directo-sku',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Actualización de productos',
            'event_type_id' => 'product.updated',
            'type' => 'webhook',
            'active' => true,
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('products', 'sku', 'PLAN-200', ['sku'])
            ->andReturn([
                'success' => true,
                'data' => [
                    'results' => [
                        ['id' => '3002'],
                    ],
                ],
            ]);
        $hubspotApi->shouldReceive('updateProduct')
            ->once()
            ->with('3002', [
                'default_code' => 'PLAN-200',
                'name' => 'Plan anual',
                'sku' => 'PLAN-200',
            ])
            ->andReturn([
                'success' => true,
                'data' => ['id' => '3002'],
            ]);

        $productCache = Mockery::mock(ProductCacheService::class);
        $productCache->shouldReceive('preload')->once();

        $service = new HubspotService($platform, $event, null, $hubspotApi, $productCache);
        $result = $service->updateProducts([
            [
                'default_code' => 'PLAN-200',
                'name' => 'Plan anual',
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, data_get($result, 'data.updated_count'));
    }

    public function test_product_updated_event_warns_when_creation_fallback_event_is_missing(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Hubspot corripio',
            'slug' => 'hubspot-corripio',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Actualización de productos',
            'event_type_id' => 'product.updated',
            'type' => 'webhook',
            'active' => true,
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('products', 'identificador_db', 'MC-000088888', ['identificador_db'])
            ->andReturn([
                'success' => true,
                'data' => [
                    'results' => [],
                ],
            ]);
        $hubspotApi->shouldNotReceive('updateProduct');

        $productCache = Mockery::mock(ProductCacheService::class);
        $productCache->shouldNotReceive('preload');

        $service = new HubspotService($platform, $event, null, $hubspotApi, $productCache);
        $result = $service->updateProducts([
            [
                'identificador_db' => 'MC-000088888',
                'name' => 'Producto sin fallback',
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('warning', $result['status'] ?? null);
        $this->assertSame('missing_create_fallback_event', data_get($result, 'data.warning_reason'));
    }
}

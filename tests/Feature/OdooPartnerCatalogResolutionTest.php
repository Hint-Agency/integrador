<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Services\Odoo\OdooApiService;
use App\Services\Odoo\OdooService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OdooPartnerCatalogResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_omits_unresolved_relational_fields_before_sending_partner_to_odoo(): void
    {
        $platform = $this->odooPlatform();
        $api = Mockery::mock(OdooApiService::class);

        $api->shouldReceive('executeKw')
            ->andReturnUsing(function (string $model, string $method, array $args = [], array $kwargs = []) {
                if ($model === 'res.partner' && $method === 'create') {
                    $payload = $args[0] ?? [];

                    $this->assertArrayNotHasKey('country_id', $payload);
                    $this->assertArrayNotHasKey('user_id', $payload);
                    $this->assertSame('Directo Group', $payload['name'] ?? null);

                    return ['success' => true, 'data' => ['result' => 777]];
                }

                return ['success' => true, 'data' => ['result' => []]];
            });

        $service = new OdooService($platform, null, null, $api);

        $result = $service->resPartnerCreateCompany([
            'name' => 'Directo Group',
            'vat' => 'DG010101AA1',
            'country_id' => 'País inexistente',
            'user_id' => 'usuario-inexistente',
            'hubspot_object_id' => 'company_123',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(777, $result['data']['id']);
    }

    public function test_it_resolves_configured_relational_fields_to_odoo_ids(): void
    {
        $platform = $this->odooPlatform([
            'odoo' => [
                'catalogs' => [
                    'aliases' => [
                        'country_id' => [
                            'México' => ['MX'],
                        ],
                    ],
                ],
            ],
        ]);
        $api = Mockery::mock(OdooApiService::class);

        $api->shouldReceive('executeKw')
            ->andReturnUsing(function (string $model, string $method, array $args = [], array $kwargs = []) {
                if ($model === 'res.country' && $method === 'search_read') {
                    $domain = $args[0][0] ?? [];

                    return ($domain[0] ?? null) === 'code' && ($domain[2] ?? null) === 'MX'
                        ? ['success' => true, 'data' => ['result' => [['id' => 156, 'name' => 'Mexico']]]]
                        : ['success' => true, 'data' => ['result' => []]];
                }

                if ($model === 'res.partner' && $method === 'create') {
                    $payload = $args[0] ?? [];

                    $this->assertSame(156, $payload['country_id'] ?? null);

                    return ['success' => true, 'data' => ['result' => 778]];
                }

                return ['success' => true, 'data' => ['result' => []]];
            });

        $service = new OdooService($platform, null, null, $api);

        $result = $service->resPartnerCreateCompany([
            'name' => 'Empresa México',
            'vat' => 'MXGODE561231GR9',
            'country_id' => 'México',
            'hubspot_object_id' => 'company_456',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(778, $result['data']['id']);
    }

    public function test_it_ignores_subscription_partner_fields_on_res_partner_payload(): void
    {
        $platform = $this->odooPlatform();
        $api = Mockery::mock(OdooApiService::class);

        $api->shouldReceive('executeKw')
            ->andReturnUsing(function (string $model, string $method, array $args = [], array $kwargs = []) {
                if ($model === 'res.partner' && $method === 'create') {
                    $payload = $args[0] ?? [];

                    $this->assertArrayNotHasKey('partner_id', $payload);
                    $this->assertArrayNotHasKey('partner_invoice_id', $payload);
                    $this->assertArrayNotHasKey('partner_shipping_id', $payload);
                    $this->assertArrayNotHasKey('odoo_id', $payload);

                    return ['success' => true, 'data' => ['result' => 779]];
                }

                return ['success' => true, 'data' => ['result' => []]];
            });

        $service = new OdooService($platform, null, null, $api);

        $result = $service->resPartnerCreateCompany([
            'name' => 'Partner Guard',
            'vat' => 'PG010101AA1',
            'partner_id' => 55,
            'partner_invoice_id' => 56,
            'partner_shipping_id' => 57,
            'odoo_id' => 55,
            'hubspot_object_id' => 'company_guard',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(779, $result['data']['id']);
    }

    private function odooPlatform(array $settings = []): Platform
    {
        return Platform::query()->create([
            'name' => 'Odoo',
            'slug' => 'odoo',
            'type' => 'odoo',
            'active' => true,
            'credentials' => [
                'database' => 'odoo',
                'username' => 'user',
                'password' => 'pass',
            ],
            'settings' => array_replace_recursive([
                'url' => 'https://odoo.test',
            ], $settings),
        ]);
    }
}

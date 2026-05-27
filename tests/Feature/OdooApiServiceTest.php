<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Services\Odoo\OdooApiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OdooApiServiceTest extends TestCase
{
    public function test_execute_kw_returns_data_when_login_and_call_are_successful(): void
    {
        config()->set('odoo.url', 'https://odoo.test');
        config()->set('odoo.database', 'odoo_db');
        config()->set('odoo.username', 'odoo_user');
        config()->set('odoo.password', 'odoo_pass');

        Http::fake([
            'https://odoo.test/jsonrpc' => Http::sequence()
                ->push(['result' => 99], 200)
                ->push(['result' => [['id' => 1, 'name' => 'A']]], 200),
        ]);

        $service = app(OdooApiService::class);
        $result = $service->executeKw(
            'res.partner',
            'search_read',
            [[]],
            ['fields' => ['name']]
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, count($result['data']['result']));
    }

    public function test_execute_kw_can_use_platform_json_rpc_settings_without_env_credentials(): void
    {
        config()->set('odoo.url', null);
        config()->set('odoo.database', null);
        config()->set('odoo.username', null);
        config()->set('odoo.password', null);

        $platform = new Platform([
            'name' => 'Odoo directoGroup',
            'slug' => 'odoo-directogroup',
            'type' => 'odoo',
            'credentials' => [
                'database' => 'directo_odoo',
                'username' => 'odoo_user',
                'password' => 'odoo_pass',
            ],
            'settings' => [
                'url' => 'https://odoo-directo.test',
                'odoo' => [
                    'adapter' => 'json_rpc',
                ],
            ],
        ]);

        Http::fake([
            'https://odoo-directo.test/jsonrpc' => Http::sequence()
                ->push(['result' => 77], 200)
                ->push(['result' => [['id' => 10, 'name' => 'Directo']]], 200),
        ]);

        $service = app()->make(OdooApiService::class, [
            'platform' => $platform,
        ]);

        $result = $service->executeKw('res.partner', 'search_read', [[]], ['fields' => ['name']]);

        $this->assertTrue($result['success']);
        $this->assertSame('json_rpc', $result['adapter']);
        $this->assertSame(10, $result['data']['result'][0]['id']);
    }

    public function test_ripcord_dependency_is_available_for_xml_rpc_adapter(): void
    {
        $this->assertTrue(class_exists(\Ripcord\Ripcord::class));
    }
}

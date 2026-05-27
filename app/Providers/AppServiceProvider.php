<?php

namespace App\Providers;

use App\Services\Aspel\AspelService;
use App\Services\AzureSql\AzureSqlService;
use App\Services\Generic\GenericPlatformService;
use App\Services\Hubspot\HubspotService;
use App\Services\NetSuite\NetSuiteService;
use App\Services\Odoo\OdooService;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as FoundationEventServiceProvider;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        FoundationEventServiceProvider::disableEventDiscovery();

        $this->app->singleton('platformClassList', function () {
            return [
                'hubspot' => HubspotService::class,
                'odoo' => OdooService::class,
                'netsuite' => NetSuiteService::class,
                'generic' => GenericPlatformService::class,
            ];
        });

        $this->app->singleton('platformDriverClassList', function () {
            return [
                'aspel' => AspelService::class,
                'azure_sql' => AzureSqlService::class,
            ];
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        if (! config('app.validate_required_env', true)) {
            return;
        }

        $this->assertRequiredEnvironment();
    }

    private function assertRequiredEnvironment(): void
    {
        $defaultConnection = (string) config('database.default');
        $defaultDbConfig = config('database.connections.'.$defaultConnection, []);

        $checks = [
            'DB_CONNECTION' => $defaultConnection,
            'DB_DATABASE' => $defaultDbConfig['database'] ?? null,
            'HUBSPOT_ACCESS_TOKEN' => config('hubspot.access_token'),
            'NETSUITE_ACCOUNT' => config('netsuite.account'),
            'NETSUITE_CONSUMER_KEY' => config('netsuite.consumer_key'),
            'NETSUITE_CONSUMER_SECRET' => config('netsuite.consumer_secret'),
            'NETSUITE_TOKEN_ID' => config('netsuite.token_id'),
            'NETSUITE_TOKEN_SECRET' => config('netsuite.token_secret'),
            'NETSUITE_PRIVATE_KEY' => config('netsuite.private_key'),
        ];

        if ($defaultConnection !== 'sqlite') {
            $checks += [
                'DB_HOST' => $defaultDbConfig['host'] ?? null,
                'DB_PORT' => $defaultDbConfig['port'] ?? null,
                'DB_USERNAME' => $defaultDbConfig['username'] ?? null,
                'DB_PASSWORD' => $defaultDbConfig['password'] ?? null,
            ];
        }

        $missing = [];
        foreach ($checks as $key => $value) {
            if ($value === null) {
                $missing[] = $key;

                continue;
            }

            if (is_string($value) && trim($value) === '') {
                $missing[] = $key;
            }
        }

        if (empty($missing)) {
            return;
        }

        throw new RuntimeException(
            'Missing required environment/config values: '.implode(', ', $missing).'.'
        );
    }
}

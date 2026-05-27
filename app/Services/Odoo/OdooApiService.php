<?php

namespace App\Services\Odoo;

use App\Models\Platform;
use App\Services\Odoo\Contracts\OdooApiPort;
use App\Services\RateLimitService;
use Illuminate\Support\Arr;

class OdooApiService
{
    public function __construct(
        protected RateLimitService $rateLimitService,
        protected $platform = null
    ) {
        if (! $this->platform instanceof Platform) {
            $this->platform = null;
        }
    }

    public function authenticate(): array
    {
        $config = $this->config();
        $this->validateConfig($config);

        return $this->adapter($config)->authenticate();
    }

    public function executeKw(string $model, string $method, array $args = [], array $kwargs = []): array
    {
        $config = $this->config();
        $this->validateConfig($config);

        return $this->adapter($config)->executeKw($model, $method, $args, $kwargs);
    }

    /**
     * @return list<string>
     */
    public function missingConfigKeys(): array
    {
        $config = $this->config();
        $missing = [];

        foreach (['url', 'database', 'username', 'password'] as $key) {
            if (! isset($config[$key]) || $config[$key] === null || $config[$key] === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function config(): array
    {
        $credentials = $this->platform instanceof Platform ? ($this->platform->credentials ?? []) : [];
        $settings = $this->platform instanceof Platform ? ($this->platform->settings ?? []) : [];
        $odooSettings = Arr::get($settings, 'odoo', []);
        $odooCredentials = Arr::get($credentials, 'odoo', []);

        return [
            'url' => Arr::get($odooSettings, 'url')
                ?? Arr::get($settings, 'url')
                ?? Arr::get($settings, 'base_url')
                ?? Arr::get($settings, 'api_url')
                ?? config('odoo.url'),
            'database' => Arr::get($odooCredentials, 'database')
                ?? Arr::get($credentials, 'database')
                ?? config('odoo.database'),
            'username' => Arr::get($odooCredentials, 'username')
                ?? Arr::get($credentials, 'username')
                ?? config('odoo.username'),
            'password' => Arr::get($odooCredentials, 'password')
                ?? Arr::get($credentials, 'password')
                ?? config('odoo.password'),
            'timeout_seconds' => (int) (
                Arr::get($odooSettings, 'timeout_seconds')
                ?? Arr::get($settings, 'timeout_seconds')
                ?? config('odoo.timeout_seconds', 30)
            ),
            'adapter' => $this->resolveAdapter($credentials, $settings),
        ];
    }

    private function validateConfig(array $config): void
    {
        foreach (['url', 'database', 'username', 'password'] as $key) {
            if (! isset($config[$key]) || $config[$key] === null || $config[$key] === '') {
                throw new \RuntimeException('Missing Odoo config key: '.$key);
            }
        }
    }

    private function resolveAdapter(array $credentials, array $settings): string
    {
        $adapter = Arr::get($settings, 'odoo.adapter')
            ?? Arr::get($settings, 'odoo.protocol')
            ?? Arr::get($settings, 'odoo_adapter')
            ?? Arr::get($settings, 'protocol')
            ?? Arr::get($credentials, 'odoo.adapter')
            ?? Arr::get($credentials, 'adapter');

        if (is_string($adapter) && trim($adapter) !== '') {
            return match (strtolower(trim($adapter))) {
                'xmlrpc', 'xml-rpc', 'xml_rpc' => 'xml_rpc',
                default => 'json_rpc',
            };
        }

        return $this->platform instanceof Platform ? 'xml_rpc' : 'json_rpc';
    }

    private function adapter(array $config): OdooApiPort
    {
        if (($config['adapter'] ?? 'json_rpc') === 'xml_rpc') {
            return new OdooXmlRpcAdapter($config);
        }

        return new OdooJsonRpcAdapter($this->rateLimitService, $config);
    }
}

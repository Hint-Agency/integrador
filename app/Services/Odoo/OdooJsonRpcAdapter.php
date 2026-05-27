<?php

namespace App\Services\Odoo;

use App\Services\Odoo\Contracts\OdooApiPort;
use App\Services\RateLimitService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OdooJsonRpcAdapter implements OdooApiPort
{
    public function __construct(
        protected RateLimitService $rateLimitService,
        protected array $config
    ) {}

    public function authenticate(): array
    {
        $response = $this->jsonRpcCall('/jsonrpc', [
            'service' => 'common',
            'method' => 'login',
            'args' => [
                $this->config['database'],
                $this->config['username'],
                $this->config['password'],
            ],
        ]);

        if (! $response['success']) {
            return $response;
        }

        return [
            'success' => true,
            'uid' => (int) ($response['data']['result'] ?? 0),
            'status_code' => $response['status_code'],
            'adapter' => 'json_rpc',
        ];
    }

    public function executeKw(string $model, string $method, array $args = [], array $kwargs = []): array
    {
        $auth = $this->authenticate();
        if (! $auth['success']) {
            return $auth;
        }

        return $this->jsonRpcCall('/jsonrpc', [
            'service' => 'object',
            'method' => 'execute_kw',
            'args' => [
                $this->config['database'],
                $auth['uid'],
                $this->config['password'],
                $model,
                $method,
                $args,
                (object) $kwargs,
            ],
        ]);
    }

    private function jsonRpcCall(string $path, array $params): array
    {
        $url = rtrim((string) $this->config['url'], '/').'/'.ltrim($path, '/');

        $this->rateLimitService->throttle('odoo', $path);

        /** @var Response $response */
        $response = Http::timeout((int) $this->config['timeout_seconds'])
            ->acceptJson()
            ->post($url, [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => $params,
                'id' => uniqid('odoo_', true),
            ]);

        $decoded = $response->json() ?? [];

        if ($response->failed() || isset($decoded['error'])) {
            return [
                'success' => false,
                'status_code' => $response->status(),
                'message' => 'Odoo JSON-RPC request failed.',
                'adapter' => 'json_rpc',
                'error' => $decoded['error'] ?? ['raw' => $response->body()],
            ];
        }

        return [
            'success' => true,
            'status_code' => $response->status(),
            'adapter' => 'json_rpc',
            'data' => $decoded,
        ];
    }
}

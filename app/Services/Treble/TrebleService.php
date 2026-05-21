<?php

namespace App\Services\Treble;

use App\Models\PlatformConnection;
use App\Models\TrebleTemplate;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class TrebleService
{
    public function sendTemplate(PlatformConnection $connection, TrebleTemplate $template, array $contact, array $context = []): array
    {
        $method = 'POST';
        $baseUrl = rtrim((string) ($connection->base_url ?? ''), '/');
        $pollId = trim((string) $template->external_template_id);
        $sendPath = (string) ($connection->settings['send_path'] ?? '/deployment/api/poll/{poll_id}');

        if ($baseUrl === '' || $pollId === '') {
            return $this->errorResponse(0, 'Treble connection is missing base_url or poll_id.');
        }

        $headers = $this->resolveHeaders($connection);
        $payload = $this->buildPayload($connection, $template, $contact, $context);
        $resolvedPath = str_replace('{poll_id}', rawurlencode($pollId), $sendPath);
        $resolvedPath = '/' . ltrim($resolvedPath, '/');
        $url = $baseUrl . $resolvedPath;

        /** @var Response $response */
        $response = Http::withHeaders($headers)
            ->acceptJson()
            ->timeout((int) ($connection->settings['timeout_seconds'] ?? 20))
            ->send($method, $url, [
                'json' => $payload,
            ]);

        if ($response->failed()) {
            return $this->errorResponse(
                $response->status(),
                'Treble request failed.',
                $response->json() ?? ['raw' => $response->body()],
                $response
            );
        }

        return [
            'success' => true,
            'status_code' => $response->status(),
            'retryable' => false,
            'request_id' => $response->header('x-request-id') ?? $response->header('x-correlation-id'),
            'external_id' => Arr::get($response->json(), 'external_id')
                ?? Arr::get($response->json(), 'session.external_id')
                ?? Arr::get($response->json(), 'data.external_id')
                ?? Arr::get($response->json(), 'data.session.external_id')
                ?? Arr::get($response->json(), 'data.id')
                ?? Arr::get($response->json(), 'message_id'),
            'data' => $response->json() ?? [],
            'error' => [
                'code' => null,
                'message' => null,
                'details' => null,
            ],
        ];
    }

    private function resolveHeaders(PlatformConnection $connection): array
    {
        $headers = array_filter($connection->settings['headers'] ?? [], 'is_scalar');
        $authMode = (string) ($connection->settings['auth_mode'] ?? '');

        if ($authMode === 'bearer_api_key' && ! empty($connection->credentials['api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $connection->credentials['api_key'];
        }

        if ($authMode === 'authorization_header' && ! empty($connection->credentials['api_key'])) {
            $headers['Authorization'] = $connection->credentials['api_key'];
        }

        if ($authMode === 'header_api_key' && ! empty($connection->credentials['api_key'])) {
            $headerName = (string) ($connection->settings['api_key_header'] ?? 'X-API-Key');
            $headers[$headerName] = $connection->credentials['api_key'];
        }

        if ($authMode === 'basic_auth') {
            $user = (string) ($connection->credentials['username'] ?? '');
            $password = (string) ($connection->credentials['password'] ?? '');
            if ($user !== '' || $password !== '') {
                $headers['Authorization'] = 'Basic ' . base64_encode($user . ':' . $password);
            }
        }

        return array_merge(['Content-Type' => 'application/json'], $headers);
    }

    private function buildPayload(PlatformConnection $connection, TrebleTemplate $template, array $contact, array $context): array
    {
        $defaultCountryCode = preg_replace('/\D+/', '', (string) ($connection->settings['country_code_default'] ?? '52')) ?: '52';
        $rawPhone = (string) ($contact['phone'] ?? $contact['mobilephone'] ?? '');
        $normalizedPhone = preg_replace('/\D+/', '', $rawPhone) ?? '';
        $normalizedPhone = ltrim($normalizedPhone, '0');

        if (str_starts_with($normalizedPhone, $defaultCountryCode)) {
            $normalizedPhone = substr($normalizedPhone, strlen($defaultCountryCode));
        }

        $userSessionKeys = $this->resolveUserSessionKeys($connection, $template, $contact, $context);

        return [
            'users' => [[
                'cellphone' => $normalizedPhone,
                'country_code' => $defaultCountryCode,
                'user_session_keys' => $userSessionKeys,
            ]],
        ];
    }

    private function resolveUserSessionKeys(PlatformConnection $connection, TrebleTemplate $template, array $contact, array $context): array
    {
        $requestTemplate = $template->request_template ?? null;
        if (is_array($requestTemplate) && $requestTemplate !== []) {
            return $this->mapTemplateConfigToSessionKeys($requestTemplate, $template, $contact, $context);
        }

        $payloadMapping = $template->payload_mapping ?? [];
        if (is_array($payloadMapping) && $payloadMapping !== []) {
            return $this->mapTemplateConfigToSessionKeys($payloadMapping, $template, $contact, $context);
        }

        return [[
            'key' => 'name',
            'value' => (string) ($contact['firstname'] ?? $context['contact']['properties']['firstname'] ?? ''),
        ]];
    }

    private function mapTemplateConfigToSessionKeys(array $config, TrebleTemplate $template, array $contact, array $context): array
    {
        if (isset($config['user_session_keys']) && is_array($config['user_session_keys'])) {
            $mapped = [];

            foreach ($config['user_session_keys'] as $item) {
                if (! is_array($item) || ! isset($item['key'])) {
                    continue;
                }

                $mapped[] = [
                    'key' => (string) $item['key'],
                    'value' => $this->interpolateTemplateValue($item['value'] ?? '', $template, $contact, $context),
                ];
            }

            return $mapped !== [] ? $mapped : [[
                'key' => 'name',
                'value' => (string) ($contact['firstname'] ?? ''),
            ]];
        }

        $mapped = [];
        foreach ($config as $key => $value) {
            $mapped[] = [
                'key' => (string) $key,
                'value' => $this->interpolateTemplateValue($value, $template, $contact, $context),
            ];
        }

        return $mapped !== [] ? $mapped : [[
            'key' => 'name',
            'value' => (string) ($contact['firstname'] ?? ''),
        ]];
    }

    private function interpolateTemplateValue(mixed $value, TrebleTemplate $template, array $contact, array $context): string
    {
        if (! is_string($value)) {
            if (is_scalar($value)) {
                return (string) $value;
            }

            return json_encode($value) ?: '';
        }

        $data = [
            'contact' => $contact,
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'external_template_id' => $template->external_template_id,
            ],
            'context' => $context,
        ];

        return preg_replace_callback('/\{\{\s*([^}]+)\s*\}\}/', static function (array $matches) use ($data): string {
            $path = trim((string) ($matches[1] ?? ''));
            if ($path === '') {
                return '';
            }

            $segments = explode('.', $path);
            $current = $data;

            foreach ($segments as $segment) {
                if (! is_array($current) || ! array_key_exists($segment, $current)) {
                    return '';
                }

                $current = $current[$segment];
            }

            if (is_array($current) || is_object($current)) {
                return json_encode($current) ?: '';
            }

            return (string) $current;
        }, $value) ?? $value;
    }

    private function errorResponse(int $statusCode, string $message, array $details = [], ?Response $response = null): array
    {
        return [
            'success' => false,
            'status_code' => $statusCode,
            'retryable' => in_array($statusCode, [0, 408, 409, 425, 429, 500, 502, 503, 504], true),
            'request_id' => $response?->header('x-request-id') ?? $response?->header('x-correlation-id'),
            'external_id' => null,
            'data' => [],
            'error' => [
                'code' => $statusCode > 0 ? (string) $statusCode : null,
                'message' => $message,
                'details' => $details,
            ],
        ];
    }
}

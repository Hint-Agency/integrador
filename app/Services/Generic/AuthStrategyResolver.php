<?php

namespace App\Services\Generic;

use App\Models\Event;
use App\Models\Platform;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AuthStrategyResolver
{
    public function resolveAuthMode(Platform $platform, Event $event): string
    {
        return (string) ($event->httpConfig?->auth_mode
            ?? $event->meta['auth_mode']
            ?? $platform->settings['auth_mode']
            ?? $platform->credentials['auth_mode']
            ?? $this->inferAuthMode($platform));
    }

    public function buildBearerAuthHeaders(Platform $platform, ?Event $event = null): array
    {
        $authConfig = $this->resolveAuthConfig($platform, $event);

        $apiKey = $this->resolveConfigValue($authConfig, 'api_key', 'api_key_env')
            ?? $platform->credentials['api_key']
            ?? $platform->settings['api_key']
            ?? config('generic-platforms.auth.bearer_api_key.api_key');

        if (! $apiKey) {
            throw new \RuntimeException('Missing bearer API key for platform auth.');
        }

        $headerName = $authConfig['header_name'] ?? 'Authorization';
        if (! is_string($headerName) || trim($headerName) === '') {
            $headerName = 'Authorization';
        }

        $prefix = array_key_exists('header_prefix', $authConfig)
            ? (string) $authConfig['header_prefix']
            : 'Bearer ';

        return [
            trim($headerName) => $prefix . $apiKey,
        ];
    }

    public function buildBasicAuthHeaders(Platform $platform, ?Event $event = null): array
    {
        $authConfig = $this->resolveAuthConfig($platform, $event);

        $username = $this->resolveConfigValue($authConfig, 'username', 'username_env')
            ?? $platform->credentials['username']
            ?? $platform->settings['username']
            ?? config('generic-platforms.auth.basic_auth.username');
        $password = $this->resolveConfigValue($authConfig, 'password', 'password_env')
            ?? $platform->credentials['password']
            ?? $platform->settings['password']
            ?? config('generic-platforms.auth.basic_auth.password');

        if (! $username || ! $password) {
            throw new \RuntimeException('Missing basic auth credentials for platform.');
        }

        return [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
        ];
    }

    public function getOAuth2AccessToken(Platform $platform, ?Event $event = null): string
    {
        $cacheKey = 'oauth2:token:' . $platform->id;
        if ($event?->id) {
            $cacheKey .= ':event:' . $event->id;
        }
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $authConfig = $this->resolveAuthConfig($platform, $event);

        $tokenUrl = $this->resolveConfigValue($authConfig, 'token_url', 'token_url_env')
            ?? $platform->settings['token_url']
            ?? $platform->credentials['token_url']
            ?? config('generic-platforms.auth.oauth2_client_credentials.token_url');
        $clientId = $this->resolveConfigValue($authConfig, 'client_id', 'client_id_env')
            ?? $platform->credentials['client_id']
            ?? $platform->settings['client_id']
            ?? config('generic-platforms.auth.oauth2_client_credentials.client_id');
        $clientSecret = $this->resolveConfigValue($authConfig, 'client_secret', 'client_secret_env')
            ?? $platform->credentials['client_secret']
            ?? $platform->settings['client_secret']
            ?? config('generic-platforms.auth.oauth2_client_credentials.client_secret');
        $scopes = $authConfig['scopes']
            ?? $this->resolveCsvEnvFromConfig($authConfig, 'scopes_env')
            ?? $platform->settings['scopes']
            ?? $platform->credentials['scopes']
            ?? config('generic-platforms.auth.oauth2_client_credentials.scopes', []);

        if (! $tokenUrl || ! $clientId || ! $clientSecret) {
            throw new \RuntimeException('Missing OAuth2 client credentials configuration.');
        }

        $payload = [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];

        if (! empty($scopes)) {
            $payload['scope'] = is_array($scopes) ? implode(' ', $scopes) : (string) $scopes;
        }

        $response = Http::asForm()->post($tokenUrl, $payload);

        if (! $response->ok()) {
            throw new \RuntimeException('Unable to retrieve OAuth2 access token.');
        }

        $data = $response->json();
        $token = Arr::get($data, 'access_token');
        $expiresIn = (int) Arr::get($data, 'expires_in', 0);

        if (! $token) {
            throw new \RuntimeException('OAuth2 access token missing in response.');
        }

        $ttl = $this->resolveTokenTtl($expiresIn);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    public function buildOAuth2Headers(Platform $platform, ?Event $event = null): array
    {
        $token = $this->getOAuth2AccessToken($platform, $event);

        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    private function inferAuthMode(Platform $platform): string
    {
        if (! empty($platform->credentials['api_key'])) {
            return 'bearer_api_key';
        }

        if (! empty($platform->credentials['username']) && ! empty($platform->credentials['password'])) {
            return 'basic_auth';
        }

        if (! empty($platform->credentials['client_id']) && ! empty($platform->credentials['client_secret'])) {
            return 'oauth2_client_credentials';
        }

        return 'bearer_api_key';
    }

    private function resolveTokenTtl(int $expiresIn): int
    {
        $defaultTtl = (int) config('generic-platforms.auth.oauth2_client_credentials.token_cache_seconds', 3000);
        if ($expiresIn <= 0) {
            return $defaultTtl;
        }

        return max(60, $expiresIn - 30);
    }

    private function resolveAuthConfig(Platform $platform, ?Event $event = null): array
    {
        $event?->loadMissing('httpConfig');

        return array_merge(
            (array) ($platform->settings['auth_config'] ?? []),
            (array) ($platform->credentials['auth_config'] ?? []),
            (array) ($event?->httpConfig?->auth_config_json ?? []),
        );
    }

    private function resolveConfigValue(array $authConfig, string $valueKey, string $envKeyName): ?string
    {
        $directValue = $authConfig[$valueKey] ?? null;
        if (is_string($directValue) && trim($directValue) !== '') {
            return $directValue;
        }

        $envKey = $authConfig[$envKeyName] ?? null;
        if (! is_string($envKey) || trim($envKey) === '') {
            return null;
        }

        $envValue = env($envKey);

        if ($envValue === null || $envValue === '') {
            return null;
        }

        return (string) $envValue;
    }

    private function resolveCsvEnvFromConfig(array $authConfig, string $envKeyName): ?array
    {
        $envKey = $authConfig[$envKeyName] ?? null;
        if (! is_string($envKey) || trim($envKey) === '') {
            return null;
        }

        $value = env($envKey);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}

<?php

$csv = static function (?string $value): array {
    if ($value === null) {
        return [];
    }

    $items = array_map('trim', explode(',', $value));

    return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
};

return [
    'policy' => [
        'allowed_domains' => $csv(env('GENERIC_ALLOWED_DOMAINS')),
        'sensitive_headers' => $csv(env(
            'GENERIC_SENSITIVE_HEADERS',
            'authorization,proxy-authorization,cookie,set-cookie,x-api-key,x-api_key,api-key'
        )),
        'timeout_seconds' => (int) env('GENERIC_TIMEOUT_SECONDS', 30),
        'retry' => [
            'max_attempts' => (int) env('GENERIC_RETRY_MAX_ATTEMPTS', 3),
            'backoff_seconds' => (int) env('GENERIC_RETRY_BACKOFF_SECONDS', 5),
            'jitter' => filter_var(env('GENERIC_RETRY_JITTER', true), FILTER_VALIDATE_BOOL),
        ],
    ],
    'auth' => [
        'bearer_api_key' => [
            'api_key' => env('GENERIC_API_KEY'),
        ],
        'basic_auth' => [
            'username' => env('GENERIC_BASIC_USER'),
            'password' => env('GENERIC_BASIC_PASSWORD'),
        ],
        'oauth2_client_credentials' => [
            'token_url' => env('GENERIC_OAUTH_TOKEN_URL'),
            'client_id' => env('GENERIC_OAUTH_CLIENT_ID'),
            'client_secret' => env('GENERIC_OAUTH_CLIENT_SECRET'),
            'scopes' => $csv(env('GENERIC_OAUTH_SCOPES')),
            'token_cache_seconds' => (int) env('GENERIC_OAUTH_TOKEN_CACHE_SECONDS', 3000),
        ],
    ],
];

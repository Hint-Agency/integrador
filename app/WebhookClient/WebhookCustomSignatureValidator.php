<?php

namespace App\WebhookClient;

use App\Models\Platform;
use Illuminate\Http\Request;
use Spatie\WebhookClient\Exceptions\InvalidConfig;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

class WebhookCustomSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        $signature = $request->header($config->signatureHeaderName);
        if (! $signature) {
            $signature = $request->query($config->signatureHeaderName);
        }

        $signingSecret = $config->signingSecret;
        if (empty($signingSecret)) {
            throw InvalidConfig::signingSecretNotSet();
        }

        if ($this->validationMode($config) === 'shared_token') {
            $token = $this->sharedTokenFromRequest($request, $config);

            return is_string($token) && hash_equals($token, $signingSecret);
        }

        if (! $signature) {
            return false;
        }

        if (! $request->query($config->signatureHeaderName)) {
            $computedSignature = hash('sha256', $signingSecret . $request->getContent());

            return hash_equals($signature, $computedSignature);
        }

        return hash_equals($signature, $signingSecret);
    }

    private function validationMode(WebhookConfig $config): string
    {
        $platform = Platform::query()->where('slug', $config->name)->first();
        if (! $platform) {
            return 'hmac_sha256';
        }

        $mode = data_get($platform->settings ?? [], 'webhook.validation_mode')
            ?? data_get($platform->settings ?? [], 'webhook.validation')
            ?? data_get($platform->settings ?? [], 'webhook_signature_mode');

        if (is_string($mode) && trim($mode) !== '') {
            return match (strtolower(trim($mode))) {
                'shared-token', 'shared_token', 'plain_token', 'token' => 'shared_token',
                default => 'hmac_sha256',
            };
        }

        return $platform->type === 'odoo' ? 'shared_token' : 'hmac_sha256';
    }

    private function allowTokenInQuery(WebhookConfig $config): bool
    {
        $platform = Platform::query()->where('slug', $config->name)->first();
        if (! $platform) {
            return false;
        }

        return (bool) (
            data_get($platform->settings ?? [], 'webhook.allow_token_in_query')
            ?? data_get($platform->settings ?? [], 'webhook.allow_query_token')
            ?? false
        );
    }

    private function sharedTokenFromRequest(Request $request, WebhookConfig $config): ?string
    {
        $token = $request->header($config->signatureHeaderName);
        if (is_scalar($token) && trim((string) $token) !== '') {
            return trim((string) $token);
        }

        if (! $this->allowTokenInQuery($config)) {
            return null;
        }

        foreach ([$request->query->all(), $request->request->all()] as $source) {
            foreach ($source as $key => $value) {
                if (! is_string($key) || ! $this->isAcceptedSharedTokenKey($key, $config)) {
                    continue;
                }

                if (is_scalar($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }
        }

        return null;
    }

    private function isAcceptedSharedTokenKey(string $key, WebhookConfig $config): bool
    {
        $accepted = [
            $config->signatureHeaderName,
            'api_token',
            'API_TOKEN',
        ];

        $normalizedKey = $this->normalizeTokenKey($key);

        foreach ($accepted as $acceptedKey) {
            if ($normalizedKey === $this->normalizeTokenKey($acceptedKey)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeTokenKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
    }
}

<?php

namespace App\Services\Hubspot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HubspotCredentialValidationService
{
    public function validateAppId(
        string $baseUrl,
        string $accessToken,
        int $expectedAppId,
        int $timeoutSeconds = 20
    ): array {
        try {
            $isPrivateAppToken = str_starts_with($accessToken, 'pat-');
            $request = Http::acceptJson()->timeout(max(1, $timeoutSeconds));
            $response = $isPrivateAppToken
                ? $request->post(rtrim($baseUrl, '/').'/oauth/v2/private-apps/get/access-token-info', [
                    'tokenKey' => $accessToken,
                ])
                : $request->get(rtrim($baseUrl, '/').'/oauth/v1/access-tokens/'.rawurlencode($accessToken));
        } catch (ConnectionException) {
            return $this->error('connection_failed', 'No fue posible consultar HubSpot para validar las credenciales.');
        }

        if ($response->failed()) {
            return $this->error(
                'invalid_access_token',
                'HubSpot rechazó el access token.',
                $response->status()
            );
        }

        $metadata = $response->json();
        $actualAppId = data_get(
            $metadata,
            'appId',
            data_get($metadata, 'app_id', data_get($metadata, 'signed_access_token.appId'))
        );

        if (! is_numeric($actualAppId)) {
            return $this->error(
                'missing_app_id',
                'HubSpot no devolvió el App ID asociado al access token.',
                $response->status()
            );
        }

        if ((int) $actualAppId !== $expectedAppId) {
            return [
                'success' => false,
                'reason' => 'app_id_mismatch',
                'message' => 'El App ID no corresponde al access token configurado. HubSpot reporta el App ID '.(int) $actualAppId.'.',
                'status_code' => $response->status(),
                'actual_app_id' => (int) $actualAppId,
            ];
        }

        return [
            'success' => true,
            'status_code' => $response->status(),
            'app_id' => (int) $actualAppId,
            'hub_id' => $this->integerMetadata($metadata, 'hubId', 'hub_id'),
            'expires_in' => is_numeric(data_get($metadata, 'expires_in')) ? (int) data_get($metadata, 'expires_in') : null,
            'credential_type' => $isPrivateAppToken ? 'private_app' : 'oauth',
        ];
    }

    private function integerMetadata(array $metadata, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            $value = data_get($metadata, $key);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function error(string $reason, string $message, int $statusCode = 0): array
    {
        return [
            'success' => false,
            'reason' => $reason,
            'message' => $message,
            'status_code' => $statusCode,
        ];
    }
}

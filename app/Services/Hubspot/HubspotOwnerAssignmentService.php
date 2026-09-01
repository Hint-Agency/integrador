<?php

namespace App\Services\Hubspot;

use App\Models\AutomationFlow;
use App\Models\PlatformConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class HubspotOwnerAssignmentService
{
    public function selectOwner(AutomationFlow $flow, mixed $currentOwnerId = null): ?string
    {
        $owners = collect($flow->owner_ids ?? [])
            ->filter(fn (mixed $ownerId): bool => is_scalar($ownerId))
            ->map(fn (mixed $ownerId): string => trim((string) $ownerId))
            ->filter()
            ->unique()
            ->values();

        $currentOwner = trim((string) ($currentOwnerId ?? ''));
        if ($currentOwner !== '') {
            $owners = $owners->reject(fn (string $ownerId): bool => $ownerId === $currentOwner)->values();
        }

        if ($owners->isEmpty()) {
            return null;
        }

        return $owners->get(random_int(0, $owners->count() - 1));
    }

    public function assignOwner(
        PlatformConnection $connection,
        string $hubspotObjectId,
        string $ownerProperty,
        string $ownerId
    ): array {
        $token = (string) ($connection->credentials['access_token'] ?? '');
        if ($token === '') {
            return $this->errorResponse(0, 'HubSpot access token is not configured for owner assignment.');
        }

        $property = trim($ownerProperty);
        if ($property === '' || trim($ownerId) === '') {
            return $this->errorResponse(0, 'HubSpot owner property or owner id is missing.');
        }

        $baseUrl = rtrim((string) ($connection->base_url ?: 'https://api.hubapi.com'), '/');

        /** @var Response $response */
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) ($connection->settings['timeout_seconds'] ?? 30))
            ->patch($baseUrl.'/crm/v3/objects/contacts/'.rawurlencode($hubspotObjectId), [
                'properties' => [
                    $property => $ownerId,
                ],
            ]);

        if ($response->failed()) {
            return $this->errorResponse(
                $response->status(),
                'HubSpot owner assignment failed.',
                $response->json() ?? ['raw' => $response->body()]
            );
        }

        return [
            'success' => true,
            'status_code' => $response->status(),
            'owner_id' => $ownerId,
            'owner_property' => $property,
            'data' => $response->json() ?? [],
            'error' => null,
        ];
    }

    private function errorResponse(int $statusCode, string $message, array $details = []): array
    {
        return [
            'success' => false,
            'status_code' => $statusCode,
            'owner_id' => null,
            'owner_property' => null,
            'data' => [],
            'error' => [
                'message' => $message,
                'details' => $details,
            ],
        ];
    }
}

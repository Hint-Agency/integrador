<?php

namespace App\Services\Hubspot;

use App\Models\AutomationFlow;
use App\Models\HubspotOwner;
use App\Models\PlatformConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class HubspotOwnerAssignmentService
{
    public function selectOwner(AutomationFlow $flow, mixed $currentOwnerId = null): ?string
    {
        return DB::transaction(function () use ($flow, $currentOwnerId): ?string {
            $lockedFlow = AutomationFlow::query()
                ->lockForUpdate()
                ->find($flow->getKey());

            if (! $lockedFlow) {
                return null;
            }

            $currentOwner = trim((string) ($currentOwnerId ?? ''));
            $owners = $lockedFlow->owners()
                ->where('hubspot_owners.active', true)
                ->orderBy('hubspot_owners.id')
                ->get(['hubspot_owners.id', 'hubspot_owners.external_owner_id'])
                ->filter(fn (HubspotOwner $owner): bool => trim($owner->external_owner_id) !== ''
                    && ($currentOwner === '' || $owner->external_owner_id !== $currentOwner))
                ->values();

            if ($owners->isEmpty()) {
                return null;
            }

            if ($lockedFlow->owner_selection_strategy === 'sequential') {
                $selectedOwner = $this->selectSequentialOwner($owners, $lockedFlow->last_assigned_owner_id);
                $rotationState = null;
            } else {
                [$selectedOwner, $rotationState] = $this->selectRandomOwner(
                    $owners,
                    $lockedFlow->last_assigned_owner_id,
                    $lockedFlow->owner_rotation_state
                );
            }

            $lockedFlow->forceFill([
                'last_assigned_owner_id' => $selectedOwner->id,
                'owner_rotation_state' => $rotationState,
            ])->save();
            $flow->setAttribute('last_assigned_owner_id', $selectedOwner->id);
            $flow->setAttribute('owner_rotation_state', $rotationState);

            return $selectedOwner->external_owner_id;
        }, 3);
    }

    private function selectSequentialOwner(Collection $owners, mixed $lastAssignedOwnerId): HubspotOwner
    {
        $lastIndex = $owners->search(
            fn (HubspotOwner $owner): bool => $owner->id === (int) $lastAssignedOwnerId
        );

        if ($lastIndex === false) {
            return $owners->first();
        }

        return $owners->get(($lastIndex + 1) % $owners->count());
    }

    private function selectRandomOwner(
        Collection $owners,
        mixed $lastAssignedOwnerId,
        mixed $rotationState
    ): array {
        $eligibleOwnerIds = $owners
            ->pluck('id')
            ->map(fn (mixed $ownerId): int => (int) $ownerId)
            ->values()
            ->all();
        $state = is_array($rotationState) ? $rotationState : [];
        $stateEligibleOwnerIds = collect($state['eligible_owner_ids'] ?? [])
            ->map(fn (mixed $ownerId): int => (int) $ownerId)
            ->values()
            ->all();
        $remainingOwnerIds = collect($state['remaining_owner_ids'] ?? [])
            ->map(fn (mixed $ownerId): int => (int) $ownerId)
            ->intersect($eligibleOwnerIds)
            ->values();

        if (($state['strategy'] ?? null) !== 'random'
            || $stateEligibleOwnerIds !== $eligibleOwnerIds
            || $remainingOwnerIds->isEmpty()) {
            $remainingOwnerIds = collect($eligibleOwnerIds);
        }

        $candidates = $remainingOwnerIds;

        if ($owners->count() > 1 && $lastAssignedOwnerId !== null) {
            $withoutPrevious = $candidates
                ->reject(fn (int $ownerId): bool => $ownerId === (int) $lastAssignedOwnerId)
                ->values();

            if ($withoutPrevious->isNotEmpty()) {
                $candidates = $withoutPrevious;
            }
        }

        $selectedOwnerId = $candidates->get(random_int(0, $candidates->count() - 1));
        $selectedOwner = $owners->firstWhere('id', $selectedOwnerId);

        return [
            $selectedOwner,
            [
                'strategy' => 'random',
                'eligible_owner_ids' => $eligibleOwnerIds,
                'remaining_owner_ids' => $remainingOwnerIds
                    ->reject(fn (int $ownerId): bool => $ownerId === $selectedOwnerId)
                    ->values()
                    ->all(),
            ],
        ];
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

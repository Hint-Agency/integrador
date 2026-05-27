<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Platform;
use Illuminate\Support\Arr;

class EventMappingContextResolver
{
    /**
     * @return array{
     *     mode:string,
     *     source_platform_id:int|null,
     *     source_platform_ids:array<int,int>,
     *     target_platform_id:int|null,
     *     next_platform_id:int|null,
     *     source_label:string,
     *     target_label:string,
     *     next_label:string|null,
     *     has_upstream:bool,
     *     has_multiple_upstream_platforms:bool
     * }
     */
    public function resolve(Event $event): array
    {
        $event->loadMissing([
            'platform:id,name,slug,type',
            'to_event:id,name,platform_id',
            'to_event.platform:id,name,slug,type',
            'from_events:id,name,platform_id,to_event_id',
            'from_events.platform:id,name,slug,type',
        ]);

        $override = Arr::get($event->meta ?? [], 'mapping_context', []);
        if (! is_array($override)) {
            $override = [];
        }

        $upstreamPlatformIds = $event->from_events
            ->pluck('platform_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $hasUpstream = $upstreamPlatformIds !== [];
        $hasNext = $event->to_event !== null;

        $sourcePlatformIds = $this->resolveSourcePlatformIds($event, $override, $upstreamPlatformIds, $hasUpstream);
        $targetPlatformId = $this->resolveTargetPlatformId($event, $override, $hasUpstream, $hasNext);
        $nextPlatformId = $event->to_event?->platform_id ? (int) $event->to_event->platform_id : null;

        $platforms = Platform::query()
            ->whereIn('id', array_values(array_unique(array_filter([
                ...$sourcePlatformIds,
                $targetPlatformId,
                $nextPlatformId,
            ]))))
            ->get(['id', 'name', 'slug', 'type'])
            ->keyBy('id');

        $mode = $this->resolveMode($hasUpstream, $hasNext);

        return [
            'mode' => (string) ($override['mode'] ?? $mode),
            'source_platform_id' => $sourcePlatformIds[0] ?? null,
            'source_platform_ids' => $sourcePlatformIds,
            'target_platform_id' => $targetPlatformId,
            'next_platform_id' => $nextPlatformId,
            'source_label' => $this->label(
                $override['source_label'] ?? null,
                $sourcePlatformIds,
                $platforms,
                $hasUpstream ? 'Incoming payload' : 'Current event payload'
            ),
            'target_label' => $this->label(
                $override['target_label'] ?? null,
                $targetPlatformId ? [$targetPlatformId] : [],
                $platforms,
                'Mapping target'
            ),
            'next_label' => $this->nextLabel($override['next_label'] ?? null, $nextPlatformId, $platforms),
            'has_upstream' => $hasUpstream,
            'has_multiple_upstream_platforms' => count($sourcePlatformIds) > 1,
        ];
    }

    /**
     * @param  array<string,mixed>  $override
     * @param  array<int,int>  $upstreamPlatformIds
     * @return array<int,int>
     */
    private function resolveSourcePlatformIds(Event $event, array $override, array $upstreamPlatformIds, bool $hasUpstream): array
    {
        $overrideIds = $override['source_platform_ids'] ?? null;
        if (is_array($overrideIds)) {
            $ids = array_values(array_unique(array_filter(array_map(
                static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
                $overrideIds
            ))));

            if ($ids !== []) {
                return $ids;
            }
        }

        if (is_numeric($override['source_platform_id'] ?? null)) {
            return [(int) $override['source_platform_id']];
        }

        if ($hasUpstream) {
            return $upstreamPlatformIds;
        }

        return $event->platform_id ? [(int) $event->platform_id] : [];
    }

    /**
     * @param  array<string,mixed>  $override
     */
    private function resolveTargetPlatformId(Event $event, array $override, bool $hasUpstream, bool $hasNext): ?int
    {
        if (is_numeric($override['target_platform_id'] ?? null)) {
            return (int) $override['target_platform_id'];
        }

        if ($hasUpstream || ! $hasNext) {
            return $event->platform_id ? (int) $event->platform_id : null;
        }

        return $event->to_event?->platform_id ? (int) $event->to_event->platform_id : null;
    }

    private function resolveMode(bool $hasUpstream, bool $hasNext): string
    {
        if ($hasUpstream && $hasNext) {
            return 'incoming_to_current_with_next';
        }

        if ($hasUpstream) {
            return 'incoming_to_current';
        }

        if ($hasNext) {
            return 'current_to_next';
        }

        return 'current_to_current';
    }

    /**
     * @param  array<int,int>  $platformIds
     * @param  \Illuminate\Support\Collection<int, Platform>  $platforms
     */
    private function label(mixed $override, array $platformIds, $platforms, string $fallback): string
    {
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        $names = collect($platformIds)
            ->map(static fn (int $id): ?string => $platforms->get($id)?->name)
            ->filter()
            ->values()
            ->all();

        if ($names === []) {
            return $fallback;
        }

        return implode(' / ', $names);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Platform>  $platforms
     */
    private function nextLabel(mixed $override, ?int $nextPlatformId, $platforms): ?string
    {
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        if ($nextPlatformId === null) {
            return null;
        }

        return $platforms->get($nextPlatformId)?->name ?? 'Next event';
    }
}

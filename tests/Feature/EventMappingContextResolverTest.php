<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Platform;
use App\Services\EventMappingContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventMappingContextResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_simple_event_with_next_maps_current_platform_to_next_platform(): void
    {
        [$hubspot, $odoo] = $this->platforms();
        $target = $this->event($odoo, 'Odoo Create');
        $event = $this->event($hubspot, 'HubSpot Source', [
            'to_event_id' => $target->id,
        ]);

        $context = app(EventMappingContextResolver::class)->resolve($event);

        $this->assertSame('current_to_next', $context['mode']);
        $this->assertSame([$hubspot->id], $context['source_platform_ids']);
        $this->assertSame($odoo->id, $context['target_platform_id']);
        $this->assertSame($odoo->id, $context['next_platform_id']);
    }

    public function test_intermediate_event_maps_upstream_payload_to_current_platform_and_keeps_next_informational(): void
    {
        [$hubspot, $odoo] = $this->platforms();
        $writeback = $this->event($hubspot, 'HubSpot Writeback');
        $current = $this->event($odoo, 'Odoo Subscription', [
            'to_event_id' => $writeback->id,
        ]);
        $this->event($hubspot, 'HubSpot Fetch', [
            'to_event_id' => $current->id,
        ]);

        $context = app(EventMappingContextResolver::class)->resolve($current);

        $this->assertSame('incoming_to_current_with_next', $context['mode']);
        $this->assertSame([$hubspot->id], $context['source_platform_ids']);
        $this->assertSame($odoo->id, $context['target_platform_id']);
        $this->assertSame($hubspot->id, $context['next_platform_id']);
    }

    public function test_event_without_next_maps_current_platform_to_itself(): void
    {
        [$hubspot] = $this->platforms();
        $event = $this->event($hubspot, 'Standalone Event');

        $context = app(EventMappingContextResolver::class)->resolve($event);

        $this->assertSame('current_to_current', $context['mode']);
        $this->assertSame([$hubspot->id], $context['source_platform_ids']);
        $this->assertSame($hubspot->id, $context['target_platform_id']);
        $this->assertNull($context['next_platform_id']);
    }

    public function test_explicit_mapping_context_override_wins_for_ambiguous_upstream(): void
    {
        [$hubspot, $odoo, $netsuite] = $this->platforms();
        $current = $this->event($odoo, 'Odoo Subscription', [
            'meta' => [
                'mapping_context' => [
                    'source_platform_id' => $netsuite->id,
                    'target_platform_id' => $odoo->id,
                    'source_label' => 'NetSuite enriched payload',
                    'target_label' => 'Odoo subscription',
                ],
            ],
        ]);
        $this->event($hubspot, 'HubSpot Fetch', ['to_event_id' => $current->id]);
        $this->event($netsuite, 'NetSuite Fetch', ['to_event_id' => $current->id]);

        $context = app(EventMappingContextResolver::class)->resolve($current);

        $this->assertSame([$netsuite->id], $context['source_platform_ids']);
        $this->assertSame($odoo->id, $context['target_platform_id']);
        $this->assertSame('NetSuite enriched payload', $context['source_label']);
        $this->assertSame('Odoo subscription', $context['target_label']);
    }

    public function test_multiple_upstream_platforms_are_available_when_no_override_is_defined(): void
    {
        [$hubspot, $odoo, $netsuite] = $this->platforms();
        $current = $this->event($odoo, 'Odoo Subscription');
        $this->event($hubspot, 'HubSpot Fetch', ['to_event_id' => $current->id]);
        $this->event($netsuite, 'NetSuite Fetch', ['to_event_id' => $current->id]);

        $context = app(EventMappingContextResolver::class)->resolve($current);

        $this->assertTrue($context['has_multiple_upstream_platforms']);
        $this->assertEqualsCanonicalizing([$hubspot->id, $netsuite->id], $context['source_platform_ids']);
        $this->assertSame($odoo->id, $context['target_platform_id']);
    }

    /**
     * @return array<int, Platform>
     */
    private function platforms(): array
    {
        return [
            Platform::query()->create(['name' => 'HubSpot', 'slug' => 'hubspot', 'type' => 'hubspot', 'active' => true]),
            Platform::query()->create(['name' => 'Odoo', 'slug' => 'odoo', 'type' => 'odoo', 'active' => true]),
            Platform::query()->create(['name' => 'NetSuite', 'slug' => 'netsuite', 'type' => 'netsuite', 'active' => true]),
        ];
    }

    private function event(Platform $platform, string $name, array $overrides = []): Event
    {
        return Event::query()->create(array_merge([
            'platform_id' => $platform->id,
            'name' => $name,
            'event_type_id' => 'generic.external.call',
            'type' => 'node',
            'active' => true,
            'meta' => [],
        ], $overrides));
    }
}

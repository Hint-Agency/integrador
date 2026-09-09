<?php

namespace Tests\Feature\Lite;

use App\Models\AutomationFlow;
use App\Models\Client;
use App\Models\HubspotOwner;
use App\Services\Hubspot\HubspotOwnerAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HubspotOwnerAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequential_strategy_rotates_all_eligible_owners_in_registration_order(): void
    {
        [$flow, $owners] = $this->flowWithOwners('sequential');
        $service = app(HubspotOwnerAssignmentService::class);

        $selected = collect(range(1, 7))
            ->map(fn (): ?string => $service->selectOwner($flow))
            ->all();

        $this->assertSame([
            $owners[0]->external_owner_id,
            $owners[1]->external_owner_id,
            $owners[2]->external_owner_id,
            $owners[0]->external_owner_id,
            $owners[1]->external_owner_id,
            $owners[2]->external_owner_id,
            $owners[0]->external_owner_id,
        ], $selected);
    }

    public function test_random_strategy_assigns_every_owner_once_per_cycle_without_immediate_repetition(): void
    {
        [$flow, $owners] = $this->flowWithOwners('random');
        $service = app(HubspotOwnerAssignmentService::class);
        $eligibleOwnerIds = collect($owners)->pluck('external_owner_id')->sort()->values()->all();

        $selected = collect(range(1, 12))
            ->map(fn (): ?string => $service->selectOwner($flow));

        $selected->each(fn (?string $ownerId) => $this->assertNotNull($ownerId));
        $selected->sliding(2)->each(
            fn ($pair) => $this->assertNotSame($pair->first(), $pair->last())
        );

        foreach ($selected->chunk(3) as $cycle) {
            $this->assertSame($eligibleOwnerIds, $cycle->sort()->values()->all());
        }
    }

    public function test_random_strategy_allows_the_only_eligible_owner_to_repeat(): void
    {
        [$flow, $owners] = $this->flowWithOwners('random', 1);
        $service = app(HubspotOwnerAssignmentService::class);

        $this->assertSame($owners[0]->external_owner_id, $service->selectOwner($flow));
        $this->assertSame($owners[0]->external_owner_id, $service->selectOwner($flow));
    }

    private function flowWithOwners(string $strategy, int $ownerCount = 3): array
    {
        $client = Client::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'active' => true,
        ]);
        $flow = AutomationFlow::query()->create([
            'client_id' => $client->id,
            'name' => 'Owner rotation',
            'priority' => 100,
            'trigger_property' => 'campus_de_interes',
            'trigger_value' => 'CDMX',
            'conditions' => [],
            'owner_property' => 'hubspot_owner_id',
            'owner_selection_strategy' => $strategy,
            'continue_to_treble' => false,
            'active' => true,
        ]);
        $owners = collect(range(1, $ownerCount))
            ->map(fn (int $index): HubspotOwner => HubspotOwner::query()->create([
                'client_id' => $client->id,
                'name' => "Owner {$index}",
                'external_owner_id' => "owner-{$index}",
                'active' => true,
            ]));
        $flow->owners()->sync($owners->pluck('id'));

        return [$flow, $owners->values()->all()];
    }
}

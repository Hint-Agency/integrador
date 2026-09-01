<?php

namespace Tests\Feature\Lite;

use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TrebleTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerAssignmentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_automation_flow_can_be_created_for_client(): void
    {
        [$actor, $client] = $this->actorAndClient();

        $this->actingAs($actor)->post("/admin/clients/{$client->id}/owners", [
            'name' => 'Ana Lopez',
            'external_owner_id' => 'owner-123',
            'email' => 'ana@example.com',
            'active' => true,
        ])->assertRedirect("/admin/clients/{$client->id}/owners");

        $owner = $client->hubspotOwners()->firstOrFail();

        $response = $this->actingAs($actor)->post("/admin/clients/{$client->id}/flows", [
            'name' => 'Asignar Cancun',
            'priority' => 200,
            'trigger_property' => 'campus_de_interes',
            'trigger_value' => 'Cancun',
            'conditions' => [
                'match' => 'all',
                'groups' => [[
                    'match' => 'all',
                    'rules' => [[
                        'property' => 'hs_analytics_source',
                        'operator' => 'not_in',
                        'value' => 'OFFLINE, REFERRALS',
                    ]],
                ]],
            ],
            'owner_assignment_enabled' => true,
            'owner_property' => 'hubspot_owner_id',
            'owner_selection_strategy' => 'random',
            'existing_owner_behavior' => 'stop',
            'owner_ids' => [$owner->id],
            'continue_to_treble' => false,
            'active' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $flow = $client->automationFlows()->firstOrFail();
        $response->assertRedirect("/admin/clients/{$client->id}/flows/{$flow->id}/edit");
        $this->assertSame([$owner->id], $flow->owners()->pluck('hubspot_owners.id')->all());
        $this->assertSame('not_in', $flow->conditions['groups'][0]['rules'][0]['operator']);
    }

    public function test_owner_from_another_client_cannot_be_added_to_rule(): void
    {
        [$actor, $client] = $this->actorAndClient();
        $otherClient = Client::query()->create(['name' => 'Other', 'slug' => 'other', 'active' => true]);
        $foreignOwner = $otherClient->hubspotOwners()->create([
            'name' => 'Foreign Owner',
            'external_owner_id' => 'foreign-1',
            'active' => true,
        ]);

        $response = $this->actingAs($actor)
            ->from("/admin/clients/{$client->id}/flows/create")
            ->post("/admin/clients/{$client->id}/flows", [
                'name' => 'Invalid rule',
                'priority' => 100,
                'trigger_property' => 'campus_de_interes',
                'trigger_value' => 'Cancun',
                'conditions' => [],
                'owner_assignment_enabled' => true,
                'owner_property' => 'hubspot_owner_id',
                'owner_selection_strategy' => 'random',
                'existing_owner_behavior' => 'stop',
                'owner_ids' => [$foreignOwner->id],
                'continue_to_treble' => false,
                'active' => true,
            ]);

        $response->assertSessionHasErrors('owner_ids.0');
        $this->assertDatabaseCount('owner_assignment_rules', 0);
    }

    public function test_existing_owner_behavior_must_be_a_supported_policy(): void
    {
        [$actor, $client] = $this->actorAndClient();
        $owner = $client->hubspotOwners()->create([
            'name' => 'Ana Lopez',
            'external_owner_id' => 'owner-123',
            'active' => true,
        ]);

        $response = $this->actingAs($actor)
            ->from("/admin/clients/{$client->id}/flows/create")
            ->post("/admin/clients/{$client->id}/flows", [
                'name' => 'Invalid policy',
                'priority' => 100,
                'trigger_property' => 'campus_de_interes',
                'trigger_value' => 'Cancun',
                'conditions' => [],
                'owner_assignment_enabled' => true,
                'owner_property' => 'hubspot_owner_id',
                'owner_selection_strategy' => 'random',
                'existing_owner_behavior' => 'overwrite',
                'owner_ids' => [$owner->id],
                'continue_to_treble' => true,
                'active' => true,
            ]);

        $response->assertSessionHasErrors('existing_owner_behavior');
        $this->assertDatabaseCount('owner_assignment_rules', 0);

        $response = $this->actingAs($actor)
            ->from("/admin/clients/{$client->id}/flows/create")
            ->post("/admin/clients/{$client->id}/flows", [
                'name' => 'Contradictory owner condition',
                'priority' => 100,
                'trigger_property' => 'campus_de_interes',
                'trigger_value' => 'Cancun',
                'conditions' => [
                    'match' => 'all',
                    'groups' => [[
                        'match' => 'all',
                        'rules' => [[
                            'property' => 'hubspot_owner_id',
                            'operator' => 'is_empty',
                            'value' => null,
                        ]],
                    ]],
                ],
                'owner_assignment_enabled' => true,
                'owner_property' => 'hubspot_owner_id',
                'owner_selection_strategy' => 'random',
                'existing_owner_behavior' => 'continue',
                'owner_ids' => [$owner->id],
                'continue_to_treble' => true,
                'active' => true,
            ]);

        $response->assertSessionHasErrors('conditions.groups.0.rules.0.property');
        $this->assertDatabaseCount('owner_assignment_rules', 0);
    }

    public function test_flow_can_disable_owner_assignment_without_configuring_owners(): void
    {
        [$actor, $client] = $this->actorAndClient();

        $response = $this->actingAs($actor)->post("/admin/clients/{$client->id}/flows", [
            'name' => 'Treble only flow',
            'priority' => 100,
            'trigger_property' => 'plantilla_de_whatsapp',
            'trigger_value' => 'Seguimiento',
            'conditions' => [
                'match' => 'all',
                'groups' => [[
                    'match' => 'all',
                    'rules' => [[
                        'property' => 'hubspot_owner_id',
                        'operator' => 'is_not_empty',
                        'value' => null,
                    ]],
                ]],
            ],
            'owner_assignment_enabled' => false,
            'owner_ids' => [],
            'continue_to_treble' => true,
            'active' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $flow = $client->automationFlows()->firstOrFail();
        $response->assertRedirect("/admin/clients/{$client->id}/flows/{$flow->id}/edit");
        $this->assertFalse($flow->owner_assignment_enabled);
        $this->assertCount(0, $flow->owners);
        $this->assertSame('hubspot_owner_id', $flow->conditions['groups'][0]['rules'][0]['property']);

        $this->actingAs($actor)
            ->from("/admin/clients/{$client->id}/flows/create")
            ->post("/admin/clients/{$client->id}/flows", [
                'name' => 'No-op flow',
                'priority' => 100,
                'trigger_property' => 'campus_de_interes',
                'conditions' => [],
                'owner_assignment_enabled' => false,
                'owner_ids' => [],
                'continue_to_treble' => false,
                'active' => true,
            ])->assertSessionHasErrors('continue_to_treble');

        $this->assertDatabaseCount('owner_assignment_rules', 1);
    }

    public function test_treble_rule_is_created_inside_the_selected_flow(): void
    {
        [$actor, $client] = $this->actorAndClient();
        $owner = $client->hubspotOwners()->create([
            'name' => 'Ana Lopez',
            'external_owner_id' => 'owner-123',
            'active' => true,
        ]);
        $flow = $client->automationFlows()->create([
            'name' => 'Lead Cancun',
            'priority' => 100,
            'trigger_property' => 'campus_de_interes',
            'trigger_value' => 'Cancun',
            'conditions' => [],
            'owner_property' => 'hubspot_owner_id',
            'owner_selection_strategy' => 'random',
            'existing_owner_behavior' => 'continue',
            'continue_to_treble' => true,
            'active' => true,
        ]);
        $flow->owners()->sync([$owner->id]);
        $template = TrebleTemplate::query()->create([
            'client_id' => $client->id,
            'name' => 'Bienvenida Cancun',
            'external_template_id' => 'poll-123',
            'request_template' => [],
            'active' => true,
        ]);

        $this->actingAs($actor)->post("/admin/clients/{$client->id}/flows/{$flow->id}/rules", [
            'name' => 'Preescolar Cancun',
            'priority' => 200,
            'treble_template_id' => $template->id,
            'conditions' => [
                'match' => 'all',
                'groups' => [[
                    'match' => 'all',
                    'rules' => [[
                        'property' => 'nivel_escolar_de_interes',
                        'operator' => 'equals',
                        'value' => 'Preescolar',
                    ]],
                ]],
            ],
            'active' => true,
        ])->assertRedirect("/admin/clients/{$client->id}/flows/{$flow->id}/edit");

        $rule = $flow->messageRules()->firstOrFail();
        $this->assertSame('campus_de_interes', $rule->trigger_property);
        $this->assertSame('Cancun', $rule->trigger_value);
        $this->assertSame('nivel_escolar_de_interes', $rule->conditions['groups'][0]['rules'][0]['property']);

        $this->actingAs($actor)->put("/admin/clients/{$client->id}/flows/{$flow->id}", [
            'name' => 'Lead por plantilla',
            'priority' => 150,
            'trigger_property' => 'plantilla_de_whatsapp',
            'trigger_value' => 'Bienvenida',
            'conditions' => [],
            'owner_assignment_enabled' => true,
            'owner_property' => 'hubspot_owner_id',
            'owner_selection_strategy' => 'random',
            'existing_owner_behavior' => 'continue',
            'owner_ids' => [$owner->id],
            'continue_to_treble' => true,
            'active' => true,
        ])->assertRedirect("/admin/clients/{$client->id}/flows/{$flow->id}/edit");

        $rule->refresh();
        $this->assertSame('plantilla_de_whatsapp', $rule->trigger_property);
        $this->assertSame('Bienvenida', $rule->trigger_value);
        $this->assertSame('continue', $flow->fresh()->existing_owner_behavior);
    }

    private function actorAndClient(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $actor = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'Integration Manager',
            'slug' => 'integration-manager-'.$actor->id,
        ]);
        $permission = Permission::query()->where('slug', 'integrations.manage')->firstOrFail();
        $role->permissions()->sync([$permission->id]);
        $actor->roles()->sync([$role->id]);

        $client = Client::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'active' => true,
        ]);

        return [$actor, $client];
    }
}

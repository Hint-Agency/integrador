<?php

namespace Tests\Feature\Lite;

use App\Jobs\HubSpot\ProcessContactPropertyChangeJob;
use App\Models\AutomationFlow;
use App\Models\Client;
use App\Models\HubspotOwner;
use App\Models\MessageRule;
use App\Models\PlatformConnection;
use App\Models\Record;
use App\Models\TrebleTemplate;
use App\Services\Lite\MessageRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientWebhookProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        $client = Client::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'active' => true,
        ]);

        PlatformConnection::query()->create([
            'client_id' => $client->id,
            'platform_type' => 'hubspot',
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'signature_header' => 'x-signature',
            'webhook_secret' => 'secret',
            'credentials' => ['access_token' => 'token'],
            'settings' => [],
            'active' => true,
        ]);

        $response = $this->postJson('/webhooks/acme/hubspot', [
            'subscriptionType' => 'contact.propertyChange',
            'objectId' => '123',
            'propertyName' => 'plantilla_de_whatsapp',
            'propertyValue' => 'Bienvenida',
        ], [
            'x-signature' => 'bad-signature',
        ]);

        $response->assertStatus(401);
    }

    public function test_webhook_processes_matching_rule_and_creates_success_record(): void
    {
        [$client, $hubspot, $treble] = $this->seedClientConnections();

        $template = TrebleTemplate::query()->create([
            'client_id' => $client->id,
            'name' => 'Bienvenida La Paz',
            'external_template_id' => 'tpl-001',
            'request_template' => [
                'template_id' => '{{template.external_template_id}}',
                'phone' => '{{contact.phone}}',
            ],
            'active' => true,
        ]);

        MessageRule::query()->create([
            'client_id' => $client->id,
            'treble_template_id' => $template->id,
            'name' => 'Regla Bienvenida',
            'priority' => 100,
            'trigger_property' => 'plantilla_de_whatsapp',
            'trigger_value' => 'Bienvenida',
            'conditions' => [
                'campus_de_interes' => 'La Paz',
            ],
            'active' => true,
        ]);

        Http::fake([
            'https://hubspot.example/crm/v3/objects/contacts/*' => Http::response([
                'id' => '123',
                'properties' => [
                    'firstname' => 'Jane',
                    'lastname' => 'Doe',
                    'phone' => '5551234',
                    'campus_de_interes' => 'La Paz',
                    'nivel_escolar_de_interes' => 'Primaria',
                    'plantilla_de_whatsapp' => 'Bienvenida',
                ],
            ], 200),
            'https://treble.example/messages/send' => Http::response([
                'id' => 'msg-100',
                'status' => 'queued',
            ], 200),
        ]);

        $payload = [
            'subscriptionType' => 'contact.propertyChange',
            'objectId' => '123',
            'propertyName' => 'plantilla_de_whatsapp',
            'propertyValue' => 'Bienvenida',
        ];

        $signature = hash('sha256', 'secret'.json_encode($payload));

        $response = $this->postJson('/webhooks/acme/hubspot', $payload, [
            'x-signature' => $signature,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('records', [
            'client_id' => $client->id,
            'status' => 'success',
            'event_type' => 'contact.propertyChange',
        ]);
    }

    public function test_highest_priority_rule_wins(): void
    {
        $client = Client::query()->create([
            'name' => 'Priority Client',
            'slug' => 'priority-client',
            'active' => true,
        ]);

        $templateOne = TrebleTemplate::query()->create([
            'client_id' => $client->id,
            'name' => 'Low Priority',
            'external_template_id' => 'tpl-low',
            'request_template' => ['template_id' => '{{template.external_template_id}}'],
            'active' => true,
        ]);
        $templateTwo = TrebleTemplate::query()->create([
            'client_id' => $client->id,
            'name' => 'High Priority',
            'external_template_id' => 'tpl-high',
            'request_template' => ['template_id' => '{{template.external_template_id}}'],
            'active' => true,
        ]);

        MessageRule::query()->create([
            'client_id' => $client->id,
            'treble_template_id' => $templateOne->id,
            'name' => 'Low',
            'priority' => 10,
            'trigger_property' => 'plantilla_de_whatsapp',
            'trigger_value' => 'B',
            'conditions' => ['campus_de_interes' => 'Cancun'],
            'active' => true,
        ]);
        MessageRule::query()->create([
            'client_id' => $client->id,
            'treble_template_id' => $templateTwo->id,
            'name' => 'High',
            'priority' => 100,
            'trigger_property' => 'plantilla_de_whatsapp',
            'trigger_value' => 'B',
            'conditions' => ['campus_de_interes' => 'Cancun'],
            'active' => true,
        ]);

        $resolver = app(MessageRuleResolver::class);
        $resolved = $resolver->resolve($client->id, [
            'campus_de_interes' => 'Cancun',
            'plantilla_de_whatsapp' => 'B',
        ], 'plantilla_de_whatsapp', 'B');

        $this->assertNotNull($resolved);
        $this->assertSame('High', $resolved->name);
    }

    public function test_rule_can_match_nested_groups_with_any_logic(): void
    {
        $client = Client::query()->create([
            'name' => 'Grouped Client',
            'slug' => 'grouped-client',
            'active' => true,
        ]);

        $template = TrebleTemplate::query()->create([
            'client_id' => $client->id,
            'name' => 'Grouped Template',
            'external_template_id' => 'tpl-grouped',
            'request_template' => ['template_id' => '{{template.external_template_id}}'],
            'active' => true,
        ]);

        MessageRule::query()->create([
            'client_id' => $client->id,
            'treble_template_id' => $template->id,
            'name' => 'Grouped Rule',
            'priority' => 100,
            'trigger_property' => 'plantilla_de_whatsapp',
            'trigger_value' => 'Bienvenida',
            'conditions' => [
                'match' => 'all',
                'groups' => [
                    [
                        'match' => 'any',
                        'rules' => [
                            ['property' => 'campus_de_interes', 'operator' => 'equals', 'value' => 'Cancun'],
                            ['property' => 'campus_de_interes', 'operator' => 'equals', 'value' => 'La Paz'],
                        ],
                    ],
                    [
                        'match' => 'all',
                        'rules' => [
                            ['property' => 'nivel_escolar_de_interes', 'operator' => 'in', 'value' => 'Primaria, Secundaria'],
                            ['property' => 'firstname', 'operator' => 'contains', 'value' => 'Car'],
                        ],
                    ],
                ],
            ],
            'active' => true,
        ]);

        $resolver = app(MessageRuleResolver::class);
        $resolved = $resolver->resolve($client->id, [
            'plantilla_de_whatsapp' => 'Bienvenida',
            'campus_de_interes' => 'La Paz',
            'nivel_escolar_de_interes' => 'Primaria',
            'firstname' => 'Carlos',
        ], 'plantilla_de_whatsapp', 'Bienvenida');

        $this->assertNotNull($resolved);
        $this->assertSame('Grouped Rule', $resolved->name);
    }

    public function test_rule_does_not_match_when_grouped_conditions_fail(): void
    {
        $client = Client::query()->create([
            'name' => 'Grouped Client 2',
            'slug' => 'grouped-client-2',
            'active' => true,
        ]);

        $template = TrebleTemplate::query()->create([
            'client_id' => $client->id,
            'name' => 'Grouped Template 2',
            'external_template_id' => 'tpl-grouped-2',
            'request_template' => ['template_id' => '{{template.external_template_id}}'],
            'active' => true,
        ]);

        MessageRule::query()->create([
            'client_id' => $client->id,
            'treble_template_id' => $template->id,
            'name' => 'Strict Grouped Rule',
            'priority' => 100,
            'trigger_property' => 'plantilla_de_whatsapp',
            'trigger_value' => 'Bienvenida',
            'conditions' => [
                'match' => 'all',
                'groups' => [
                    [
                        'match' => 'any',
                        'rules' => [
                            ['property' => 'campus_de_interes', 'operator' => 'equals', 'value' => 'Cancun'],
                            ['property' => 'campus_de_interes', 'operator' => 'equals', 'value' => 'La Paz'],
                        ],
                    ],
                    [
                        'match' => 'all',
                        'rules' => [
                            ['property' => 'nivel_escolar_de_interes', 'operator' => 'not_equals', 'value' => 'Primaria'],
                        ],
                    ],
                ],
            ],
            'active' => true,
        ]);

        $resolver = app(MessageRuleResolver::class);
        $resolved = $resolver->resolve($client->id, [
            'plantilla_de_whatsapp' => 'Bienvenida',
            'campus_de_interes' => 'La Paz',
            'nivel_escolar_de_interes' => 'Primaria',
        ], 'plantilla_de_whatsapp', 'Bienvenida');

        $this->assertNull($resolved);
    }

    public function test_treble_error_creates_hubspot_note_result_in_record_details(): void
    {
        [$client] = $this->seedClientConnections();

        $template = TrebleTemplate::query()->create([
            'client_id' => $client->id,
            'name' => 'Error Template',
            'external_template_id' => 'tpl-error',
            'request_template' => ['template_id' => '{{template.external_template_id}}'],
            'active' => true,
        ]);

        MessageRule::query()->create([
            'client_id' => $client->id,
            'treble_template_id' => $template->id,
            'name' => 'Rule',
            'priority' => 100,
            'trigger_property' => 'plantilla_de_whatsapp',
            'trigger_value' => 'Bienvenida',
            'conditions' => [],
            'active' => true,
        ]);

        Http::fake([
            'https://hubspot.example/crm/v3/objects/contacts/*' => Http::response([
                'id' => '123',
                'properties' => [
                    'firstname' => 'Jane',
                    'lastname' => 'Doe',
                    'phone' => '5551234',
                    'campus_de_interes' => 'La Paz',
                    'plantilla_de_whatsapp' => 'Bienvenida',
                ],
            ], 200),
            'https://treble.example/messages/send' => Http::response([
                'error' => 'failed',
            ], 500),
            'https://hubspot.example/crm/v3/objects/notes' => Http::response([
                'id' => 'note-1',
            ], 201),
        ]);

        dispatch_sync(new ProcessContactPropertyChangeJob(
            $client,
            PlatformConnection::query()->where('client_id', $client->id)->where('platform_type', 'hubspot')->firstOrFail(),
            [
                'subscriptionType' => 'contact.propertyChange',
                'objectId' => '123',
                'propertyName' => 'plantilla_de_whatsapp',
                'propertyValue' => 'Bienvenida',
            ]
        ));

        $record = Record::query()->latest('id')->first();
        $this->assertSame('error', $record->status);
        $this->assertIsArray($record->details['hubspot_note'] ?? null);
        $this->assertTrue($record->details['hubspot_note']['success'] ?? false);
    }

    public function test_rule_assigns_owner_before_dispatching_treble_template(): void
    {
        [$client, $hubspot] = $this->seedClientConnections();

        $template = TrebleTemplate::query()->create([
            'client_id' => $client->id,
            'name' => 'Assigned Lead Welcome',
            'external_template_id' => 'tpl-owner',
            'request_template' => ['owner_id' => '{{contact.hubspot_owner_id}}'],
            'active' => true,
        ]);

        $owner = HubspotOwner::query()->create([
            'client_id' => $client->id,
            'name' => 'Owner A',
            'external_owner_id' => 'owner-a',
            'active' => true,
        ]);
        $flow = AutomationFlow::query()->create([
            'client_id' => $client->id,
            'name' => 'Assign Manzanillo Lead',
            'priority' => 200,
            'trigger_property' => 'campus_de_interes',
            'trigger_value' => 'Manzanillo',
            'conditions' => [
                'match' => 'all',
                'groups' => [[
                    'match' => 'all',
                    'rules' => [
                        ['property' => 'hubspot_owner_id', 'operator' => 'is_empty', 'value' => null],
                        ['property' => 'hs_analytics_source', 'operator' => 'not_in', 'value' => 'OFFLINE, REFERRALS'],
                    ],
                ]],
            ],
            'owner_property' => 'hubspot_owner_id',
            'owner_selection_strategy' => 'random',
            'continue_to_treble' => true,
            'active' => true,
        ]);
        $flow->owners()->sync([$owner->id]);
        MessageRule::query()->create([
            'client_id' => $client->id,
            'automation_flow_id' => $flow->id,
            'treble_template_id' => $template->id,
            'name' => 'Welcome after assignment',
            'priority' => 100,
            'trigger_property' => $flow->trigger_property,
            'trigger_value' => $flow->trigger_value,
            'conditions' => [],
            'active' => true,
        ]);

        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'id' => '123',
                    'properties' => [
                        'firstname' => 'Carlos',
                        'phone' => '+529991412826',
                        'campus_de_interes' => 'Manzanillo',
                        'hubspot_owner_id' => null,
                        'hs_analytics_source' => 'ORGANIC_SEARCH',
                    ],
                ], 200);
            }

            if ($request->method() === 'PATCH') {
                return Http::response([
                    'id' => '123',
                    'properties' => ['hubspot_owner_id' => 'owner-a'],
                ], 200);
            }

            return Http::response(['id' => 'treble-1'], 200);
        });

        dispatch_sync(new ProcessContactPropertyChangeJob(
            $client,
            $hubspot,
            [
                'subscriptionType' => 'contact.propertyChange',
                'objectId' => '123',
                'propertyName' => 'campus_de_interes',
                'propertyValue' => 'Manzanillo',
            ]
        ));

        $requests = collect(Http::recorded())->map(fn (array $entry): array => [
            'method' => $entry[0]->method(),
            'url' => $entry[0]->url(),
        ])->values();

        $patchIndex = $requests->search(fn (array $request): bool => $request['method'] === 'PATCH');
        $trebleIndex = $requests->search(fn (array $request): bool => str_contains($request['url'], 'treble.example'));

        $this->assertIsInt($patchIndex);
        $this->assertIsInt($trebleIndex);
        $this->assertLessThan($trebleIndex, $patchIndex);
        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && $request['properties']['hubspot_owner_id'] === 'owner-a');
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains(urldecode($request->url()), 'hs_analytics_source'));

        $record = Record::query()->latest('id')->firstOrFail();
        $this->assertSame('success', $record->status);
        $this->assertSame($flow->id, $record->details['matched_flow_id']);
        $this->assertSame('owner-a', $record->details['owner_assignment']['selected_owner_id']);
        $this->assertTrue($record->details['treble_response']['success']);
    }

    public function test_rule_can_assign_owner_without_dispatching_treble(): void
    {
        [$client, $hubspot] = $this->seedClientConnections();

        $owners = collect([
            ['name' => 'Owner A', 'external_owner_id' => 'owner-a'],
            ['name' => 'Owner B', 'external_owner_id' => 'owner-b'],
        ])->map(fn (array $owner) => HubspotOwner::query()->create([
            'client_id' => $client->id,
            ...$owner,
            'active' => true,
        ]));
        $flow = AutomationFlow::query()->create([
            'client_id' => $client->id,
            'name' => 'Assignment Only',
            'priority' => 100,
            'trigger_property' => 'campus_de_interes',
            'trigger_value' => 'Cancun',
            'conditions' => [],
            'owner_property' => 'hubspot_owner_id',
            'owner_selection_strategy' => 'random',
            'continue_to_treble' => false,
            'active' => true,
        ]);
        $flow->owners()->sync($owners->pluck('id'));

        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'id' => '123',
                    'properties' => [
                        'campus_de_interes' => 'Cancun',
                        'hubspot_owner_id' => null,
                    ],
                ], 200);
            }

            return Http::response(['id' => '123'], 200);
        });

        dispatch_sync(new ProcessContactPropertyChangeJob(
            $client,
            $hubspot,
            [
                'subscriptionType' => 'contact.propertyChange',
                'objectId' => '123',
                'propertyName' => 'campus_de_interes',
                'propertyValue' => 'Cancun',
            ]
        ));

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && in_array($request['properties']['hubspot_owner_id'], ['owner-a', 'owner-b'], true));

        $record = Record::query()->latest('id')->firstOrFail();
        $this->assertSame('success', $record->status);
        $this->assertNull($record->details['treble_response']);
    }

    public function test_owner_assignment_failure_stops_treble_and_records_hubspot_note(): void
    {
        [$client, $hubspot] = $this->seedClientConnections();

        $template = TrebleTemplate::query()->create([
            'client_id' => $client->id,
            'name' => 'Should Not Send',
            'external_template_id' => 'tpl-stop',
            'request_template' => ['name' => '{{contact.firstname}}'],
            'active' => true,
        ]);

        $owner = HubspotOwner::query()->create([
            'client_id' => $client->id,
            'name' => 'Denied Owner',
            'external_owner_id' => 'owner-denied',
            'active' => true,
        ]);
        $flow = AutomationFlow::query()->create([
            'client_id' => $client->id,
            'name' => 'Fail Assignment',
            'priority' => 100,
            'trigger_property' => 'campus_de_interes',
            'trigger_value' => 'La Paz',
            'conditions' => [],
            'owner_property' => 'hubspot_owner_id',
            'owner_selection_strategy' => 'random',
            'continue_to_treble' => true,
            'active' => true,
        ]);
        $flow->owners()->sync([$owner->id]);

        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'id' => '123',
                    'properties' => [
                        'campus_de_interes' => 'La Paz',
                        'hubspot_owner_id' => null,
                    ],
                ], 200);
            }

            if ($request->method() === 'PATCH') {
                return Http::response(['message' => 'Owner is not available'], 400);
            }

            if (str_contains($request->url(), '/crm/v3/objects/notes')) {
                return Http::response(['id' => 'note-owner-error'], 201);
            }

            return Http::response(['id' => 'unexpected-treble-request'], 200);
        });

        dispatch_sync(new ProcessContactPropertyChangeJob(
            $client,
            $hubspot,
            [
                'subscriptionType' => 'contact.propertyChange',
                'objectId' => '123',
                'propertyName' => 'campus_de_interes',
                'propertyValue' => 'La Paz',
            ]
        ));

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'treble.example'));

        $record = Record::query()->latest('id')->firstOrFail();
        $this->assertSame('error', $record->status);
        $this->assertFalse($record->details['owner_assignment']['response']['success']);
        $this->assertTrue($record->details['hubspot_note']['success']);
        $this->assertNull($record->details['treble_response']);
    }

    public function test_flow_does_not_overwrite_an_existing_owner_or_send_treble(): void
    {
        [$client, $hubspot] = $this->seedClientConnections();

        $owner = HubspotOwner::query()->create([
            'client_id' => $client->id,
            'name' => 'New Owner',
            'external_owner_id' => 'owner-new',
            'active' => true,
        ]);
        $flow = AutomationFlow::query()->create([
            'client_id' => $client->id,
            'name' => 'Do not overwrite',
            'priority' => 100,
            'trigger_property' => 'campus_de_interes',
            'trigger_value' => 'Cancun',
            'conditions' => [],
            'owner_property' => 'hubspot_owner_id',
            'owner_selection_strategy' => 'random',
            'continue_to_treble' => true,
            'active' => true,
        ]);
        $flow->owners()->sync([$owner->id]);
        $template = TrebleTemplate::query()->create([
            'client_id' => $client->id,
            'name' => 'Must stay inside flow',
            'external_template_id' => 'tpl-contained',
            'request_template' => [],
            'active' => true,
        ]);
        MessageRule::query()->create([
            'client_id' => $client->id,
            'automation_flow_id' => $flow->id,
            'treble_template_id' => $template->id,
            'name' => 'Contained rule',
            'priority' => 100,
            'trigger_property' => $flow->trigger_property,
            'trigger_value' => $flow->trigger_value,
            'conditions' => [],
            'active' => true,
        ]);

        Http::fake([
            'https://hubspot.example/crm/v3/objects/contacts/*' => Http::response([
                'id' => '123',
                'properties' => [
                    'campus_de_interes' => 'Cancun',
                    'hubspot_owner_id' => 'owner-existing',
                ],
            ], 200),
        ]);

        dispatch_sync(new ProcessContactPropertyChangeJob(
            $client,
            $hubspot,
            [
                'subscriptionType' => 'contact.propertyChange',
                'objectId' => '123',
                'propertyName' => 'campus_de_interes',
                'propertyValue' => 'Cancun',
            ]
        ));

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'PATCH'
            || str_contains($request->url(), 'treble.example'));

        $record = Record::query()->latest('id')->firstOrFail();
        $this->assertSame('warning', $record->status);
        $this->assertSame('existing_owner_stopped_flow', $record->details['reason']);
        $this->assertSame('owner-existing', $record->details['contact_properties']['hubspot_owner_id']);
    }

    public function test_flow_can_preserve_existing_owner_and_continue_to_treble(): void
    {
        [$client, $hubspot] = $this->seedClientConnections();

        $owner = HubspotOwner::query()->create([
            'client_id' => $client->id,
            'name' => 'Fallback Owner',
            'external_owner_id' => 'owner-fallback',
            'active' => true,
        ]);
        $flow = AutomationFlow::query()->create([
            'client_id' => $client->id,
            'name' => 'Message assigned contacts',
            'priority' => 100,
            'trigger_property' => 'plantilla_de_whatsapp',
            'trigger_value' => 'Seguimiento',
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
            'name' => 'Assigned contact follow-up',
            'external_template_id' => 'tpl-follow-up',
            'request_template' => [],
            'active' => true,
        ]);
        MessageRule::query()->create([
            'client_id' => $client->id,
            'automation_flow_id' => $flow->id,
            'treble_template_id' => $template->id,
            'name' => 'Follow-up rule',
            'priority' => 100,
            'trigger_property' => $flow->trigger_property,
            'trigger_value' => $flow->trigger_value,
            'conditions' => [],
            'active' => true,
        ]);

        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'id' => '123',
                    'properties' => [
                        'firstname' => 'Carlos',
                        'phone' => '+529991412826',
                        'plantilla_de_whatsapp' => 'Seguimiento',
                        'hubspot_owner_id' => 'owner-existing',
                    ],
                ], 200);
            }

            return Http::response(['id' => 'treble-follow-up'], 200);
        });

        dispatch_sync(new ProcessContactPropertyChangeJob(
            $client,
            $hubspot,
            [
                'subscriptionType' => 'contact.propertyChange',
                'objectId' => '123',
                'propertyName' => 'plantilla_de_whatsapp',
                'propertyValue' => 'Seguimiento',
            ]
        ));

        Http::assertNotSent(fn ($request): bool => $request->method() === 'PATCH');
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'treble.example'));

        $record = Record::query()->latest('id')->firstOrFail();
        $this->assertSame('success', $record->status);
        $this->assertTrue($record->details['owner_assignment']['skipped']);
        $this->assertSame('existing_owner_preserved', $record->details['owner_assignment']['reason']);
        $this->assertSame('owner-existing', $record->details['owner_assignment']['selected_owner_id']);
        $this->assertTrue($record->details['treble_response']['success']);
    }

    public function test_flow_can_skip_owner_assignment_validate_existing_owner_and_dispatch_treble(): void
    {
        [$client, $hubspot] = $this->seedClientConnections();

        $flow = AutomationFlow::query()->create([
            'client_id' => $client->id,
            'name' => 'Treble only',
            'priority' => 100,
            'trigger_property' => 'plantilla_de_whatsapp',
            'trigger_value' => 'Lista',
            'conditions' => [],
            'owner_assignment_enabled' => false,
            'continue_to_treble' => true,
            'active' => true,
        ]);
        $template = TrebleTemplate::query()->create([
            'client_id' => $client->id,
            'name' => 'List message',
            'external_template_id' => 'tpl-list',
            'request_template' => [],
            'active' => true,
        ]);
        MessageRule::query()->create([
            'client_id' => $client->id,
            'automation_flow_id' => $flow->id,
            'treble_template_id' => $template->id,
            'name' => 'List rule',
            'priority' => 100,
            'trigger_property' => $flow->trigger_property,
            'trigger_value' => $flow->trigger_value,
            'conditions' => [],
            'active' => true,
        ]);

        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'id' => '123',
                    'properties' => [
                        'firstname' => 'Carlos',
                        'phone' => '+529991412826',
                        'plantilla_de_whatsapp' => 'Lista',
                        'hubspot_owner_id' => 'owner-existing',
                    ],
                ], 200);
            }

            return Http::response(['id' => 'treble-list'], 200);
        });

        dispatch_sync(new ProcessContactPropertyChangeJob(
            $client,
            $hubspot,
            [
                'subscriptionType' => 'contact.propertyChange',
                'objectId' => '123',
                'propertyName' => 'plantilla_de_whatsapp',
                'propertyValue' => 'Lista',
            ]
        ));

        Http::assertNotSent(fn ($request): bool => $request->method() === 'PATCH');
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'treble.example'));

        $record = Record::query()->latest('id')->firstOrFail();
        $this->assertSame('success', $record->status);
        $this->assertSame('owner_assignment_disabled', $record->details['owner_assignment']['reason']);
        $this->assertTrue($record->details['owner_assignment']['skipped']);
        $this->assertSame('owner-existing', $record->details['owner_assignment']['selected_owner_id']);
        $this->assertTrue($record->details['treble_response']['success']);
    }

    public function test_flow_skipping_assignment_stops_before_treble_when_contact_has_no_owner(): void
    {
        [$client, $hubspot] = $this->seedClientConnections();

        $flow = AutomationFlow::query()->create([
            'client_id' => $client->id,
            'name' => 'Treble requires existing owner',
            'priority' => 100,
            'trigger_property' => 'plantilla_de_whatsapp',
            'trigger_value' => 'Lista',
            'conditions' => [],
            'owner_assignment_enabled' => false,
            'continue_to_treble' => true,
            'active' => true,
        ]);
        $template = TrebleTemplate::query()->create([
            'client_id' => $client->id,
            'name' => 'List message',
            'external_template_id' => 'tpl-list',
            'request_template' => [],
            'active' => true,
        ]);
        MessageRule::query()->create([
            'client_id' => $client->id,
            'automation_flow_id' => $flow->id,
            'treble_template_id' => $template->id,
            'name' => 'List rule',
            'priority' => 100,
            'trigger_property' => $flow->trigger_property,
            'trigger_value' => $flow->trigger_value,
            'conditions' => [],
            'active' => true,
        ]);

        Http::fake([
            'https://hubspot.example/crm/v3/objects/contacts/*' => Http::response([
                'id' => '123',
                'properties' => [
                    'plantilla_de_whatsapp' => 'Lista',
                    'hubspot_owner_id' => null,
                ],
            ], 200),
        ]);

        dispatch_sync(new ProcessContactPropertyChangeJob(
            $client,
            $hubspot,
            [
                'subscriptionType' => 'contact.propertyChange',
                'objectId' => '123',
                'propertyName' => 'plantilla_de_whatsapp',
                'propertyValue' => 'Lista',
            ]
        ));

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'treble.example'));

        $record = Record::query()->latest('id')->firstOrFail();
        $this->assertSame('warning', $record->status);
        $this->assertSame('missing_required_existing_owner', $record->details['reason']);
        $this->assertNull($record->details['treble_response']);
    }

    private function seedClientConnections(): array
    {
        $client = Client::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'active' => true,
        ]);

        $hubspot = PlatformConnection::query()->create([
            'client_id' => $client->id,
            'platform_type' => 'hubspot',
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'base_url' => 'https://hubspot.example',
            'signature_header' => 'x-signature',
            'webhook_secret' => 'secret',
            'credentials' => ['access_token' => 'hubspot-token'],
            'settings' => [],
            'active' => true,
        ]);

        $treble = PlatformConnection::query()->create([
            'client_id' => $client->id,
            'platform_type' => 'treble',
            'name' => 'Treble',
            'slug' => 'treble',
            'base_url' => 'https://treble.example',
            'credentials' => ['api_key' => 'treble-token'],
            'settings' => [
                'send_path' => '/messages/send',
                'auth_mode' => 'bearer_api_key',
            ],
            'active' => true,
        ]);

        return [$client, $hubspot, $treble];
    }
}

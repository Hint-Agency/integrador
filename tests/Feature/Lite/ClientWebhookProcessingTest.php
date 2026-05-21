<?php

namespace Tests\Feature\Lite;

use App\Models\Client;
use App\Models\MessageRule;
use App\Models\PlatformConnection;
use App\Models\TrebleTemplate;
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

        $signature = hash('sha256', 'secret' . json_encode($payload));

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

        $resolver = app(\App\Services\Lite\MessageRuleResolver::class);
        $resolved = $resolver->resolve($client->id, [
            'campus_de_interes' => 'Cancun',
            'plantilla_de_whatsapp' => 'B',
        ], 'plantilla_de_whatsapp', 'B');

        $this->assertNotNull($resolved);
        $this->assertSame('High', $resolved->name);
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

        dispatch_sync(new \App\Jobs\HubSpot\ProcessContactPropertyChangeJob(
            $client,
            PlatformConnection::query()->where('client_id', $client->id)->where('platform_type', 'hubspot')->firstOrFail(),
            [
                'subscriptionType' => 'contact.propertyChange',
                'objectId' => '123',
                'propertyName' => 'plantilla_de_whatsapp',
                'propertyValue' => 'Bienvenida',
            ]
        ));

        $record = \App\Models\Record::query()->latest('id')->first();
        $this->assertSame('error', $record->status);
        $this->assertIsArray($record->details['hubspot_note'] ?? null);
        $this->assertTrue($record->details['hubspot_note']['success'] ?? false);
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

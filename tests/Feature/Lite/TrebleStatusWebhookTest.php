<?php

namespace Tests\Feature\Lite;

use App\Models\Client;
use App\Models\PlatformConnection;
use App\Models\Record;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrebleStatusWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_treble_callback_requires_valid_secret(): void
    {
        [$client] = $this->seedClientAndConnection();

        $response = $this->postJson('/webhooks/'.$client->slug.'/treble/status', [
            'event_id' => 'evt-1',
            'event_type' => 'session.close',
            'session' => ['external_id' => 'ext-1'],
        ], [
            'X-Treble-Webhook-Secret' => 'wrong',
        ]);

        $response->assertStatus(401);
    }

    public function test_treble_callback_updates_matching_record_by_external_id(): void
    {
        [$client] = $this->seedClientAndConnection();

        $record = Record::query()->create([
            'client_id' => $client->id,
            'event_type' => 'contact.propertyChange',
            'status' => 'success',
            'payload' => [],
            'message' => 'Treble template dispatched successfully.',
            'details' => [
                'treble_response' => [
                    'external_id' => 'ext-123',
                ],
                'treble_request' => [
                    'template_name' => 'bienvenida',
                    'phone' => '9993543628',
                ],
            ],
        ]);

        $payload = [
            'event_id' => 'evt-123',
            'timestamp' => '2026-05-04T23:03:09.195065Z',
            'event_type' => 'session.close',
            'session' => [
                'external_id' => 'ext-123',
                'closed_at' => '2026-05-04T23:03:00Z',
            ],
            'user' => [
                'country_code' => '+52',
                'cellphone' => '19993543628',
            ],
            'hsm' => [
                'name' => 'bienvenida',
            ],
        ];

        $response = $this->postJson('/webhooks/'.$client->slug.'/treble/status', $payload, [
            'X-Treble-Webhook-Secret' => 'status-secret',
        ]);

        $response->assertOk();

        $record->refresh();
        $this->assertSame('session.close', $record->details['treble_status']['current'] ?? null);
        $this->assertSame('ext-123', $record->details['treble_status']['external_id'] ?? null);
        $this->assertCount(1, $record->details['treble_status']['history'] ?? []);
        $this->assertDatabaseHas('records', [
            'client_id' => $client->id,
            'event_type' => 'treble.status.callback',
            'status' => 'success',
        ]);
    }

    public function test_treble_callback_is_idempotent_by_event_id(): void
    {
        [$client] = $this->seedClientAndConnection();

        $record = Record::query()->create([
            'client_id' => $client->id,
            'event_type' => 'contact.propertyChange',
            'status' => 'success',
            'payload' => [],
            'message' => 'Treble template dispatched successfully.',
            'details' => [
                'treble_response' => [
                    'external_id' => 'ext-123',
                ],
            ],
        ]);

        $payload = [
            'event_id' => 'evt-123',
            'timestamp' => '2026-05-04T23:03:09.195065Z',
            'event_type' => 'session.close',
            'session' => [
                'external_id' => 'ext-123',
            ],
        ];

        $headers = ['X-Treble-Webhook-Secret' => 'status-secret'];
        $this->postJson('/webhooks/'.$client->slug.'/treble/status', $payload, $headers)->assertOk();
        $this->postJson('/webhooks/'.$client->slug.'/treble/status', $payload, $headers)->assertOk();

        $record->refresh();
        $this->assertCount(1, $record->details['treble_status']['history'] ?? []);
    }

    public function test_treble_callback_can_match_record_by_normalized_phone_when_external_id_is_missing(): void
    {
        [$client] = $this->seedClientAndConnection();

        $record = Record::query()->create([
            'client_id' => $client->id,
            'event_type' => 'contact.propertyChange',
            'status' => 'success',
            'payload' => [],
            'message' => 'Treble template dispatched successfully.',
            'details' => [
                'treble_request' => [
                    'template_name' => 'bienvenida',
                    'phone' => '529993543628',
                    'phone_normalized' => '9993543628',
                ],
            ],
        ]);

        $payload = [
            'event_id' => 'evt-phone-1',
            'timestamp' => '2026-05-04T23:03:09.195065Z',
            'event_type' => 'session.close',
            'session' => [
                'external_id' => '',
                'closed_at' => '2026-05-04T23:03:00Z',
            ],
            'user' => [
                'country_code' => '+52',
                'cellphone' => '19993543628',
            ],
            'hsm' => [
                'name' => 'bienvenida',
            ],
        ];

        $response = $this->postJson('/webhooks/'.$client->slug.'/treble/status', $payload, [
            'X-Treble-Webhook-Secret' => 'status-secret',
        ]);

        $response->assertOk();

        $record->refresh();
        $this->assertSame('session.close', $record->details['treble_status']['current'] ?? null);
        $this->assertCount(1, $record->details['treble_status']['history'] ?? []);
    }

    public function test_treble_callback_matches_historical_record_by_conversation_id(): void
    {
        [$client] = $this->seedClientAndConnection();

        $record = Record::query()->create([
            'client_id' => $client->id,
            'event_type' => 'contact.propertyChange',
            'status' => 'success',
            'payload' => [],
            'message' => 'Treble template dispatched successfully.',
            'details' => [
                'matched_rule_name' => 'Administrative rule name',
                'treble_request' => [
                    'template_name' => 'Administrative template name',
                    'phone_normalized' => '9991412826',
                ],
                'treble_response' => [
                    'external_id' => null,
                    'data' => [
                        'conversations_id' => ['conversation-123'],
                    ],
                ],
            ],
        ]);

        $response = $this->postJson('/webhooks/'.$client->slug.'/treble/status', [
            'event_id' => 'evt-conversation-1',
            'timestamp' => '2026-09-29T22:04:04Z',
            'event_type' => 'session.close',
            'session' => [
                'external_id' => 'conversation-123',
                'closed_at' => '2026-09-29T22:03:36Z',
            ],
            'user' => [
                'country_code' => '+52',
                'cellphone' => '19991412826',
            ],
            'hsm' => [
                'name' => 'technical_hsm_name',
            ],
        ], [
            'X-Treble-Webhook-Secret' => 'status-secret',
        ]);

        $response->assertOk()->assertJsonPath('results.0.matched', true)
            ->assertJsonPath('results.0.record_id', $record->id);

        $record->refresh();
        $this->assertSame('session.close', $record->details['treble_status']['current']);
        $this->assertSame('conversation-123', $record->details['treble_status']['external_id']);
    }

    public function test_deployment_failure_marks_matched_record_and_callback_as_error(): void
    {
        [$client] = $this->seedClientAndConnection();

        $record = Record::query()->create([
            'client_id' => $client->id,
            'event_type' => 'contact.propertyChange',
            'status' => 'success',
            'payload' => [],
            'message' => 'Treble template dispatched successfully.',
            'details' => [
                'treble_request' => [
                    'phone_normalized' => '9991412826',
                ],
            ],
        ]);

        $response = $this->postJson('/webhooks/'.$client->slug.'/treble/status', [
            'event_id' => 'evt-failure-1',
            'timestamp' => '2026-09-29T23:26:11Z',
            'event_type' => 'deployment.failure',
            'failure_reason' => 'FAILURE_BY_HUMAN_HANDOVER',
            'failed_at' => '2026-09-29T23:26:11Z',
            'user' => [
                'country_code' => '+52',
                'cellphone' => '9991412826',
            ],
        ], [
            'X-Treble-Webhook-Secret' => 'status-secret',
        ]);

        $response->assertOk()->assertJsonPath('results.0.matched', true)
            ->assertJsonPath('results.0.record_id', $record->id);

        $record->refresh();
        $this->assertSame('error', $record->status);
        $this->assertSame('FAILURE_BY_HUMAN_HANDOVER', $record->details['treble_status']['failure_reason']);
        $this->assertSame('Treble deployment failed: FAILURE_BY_HUMAN_HANDOVER', $record->message);

        $this->assertDatabaseHas('records', [
            'client_id' => $client->id,
            'event_type' => 'treble.status.callback',
            'status' => 'error',
        ]);
    }

    public function test_treble_callback_creates_visible_record_when_unmatched(): void
    {
        [$client] = $this->seedClientAndConnection();

        $payload = [
            'event_id' => 'evt-unmatched-1',
            'timestamp' => '2026-05-04T23:03:09.195065Z',
            'event_type' => 'session.close',
            'session' => [
                'external_id' => 'ext-missing',
            ],
            'user' => [
                'country_code' => '+52',
                'cellphone' => '19993543628',
            ],
            'hsm' => [
                'name' => 'bienvenida',
            ],
        ];

        $this->postJson('/webhooks/'.$client->slug.'/treble/status', $payload, [
            'X-Treble-Webhook-Secret' => 'status-secret',
        ])->assertOk();

        $this->assertDatabaseHas('records', [
            'client_id' => $client->id,
            'event_type' => 'treble.status.callback',
            'status' => 'warning',
        ]);
    }

    public function test_treble_connection_generates_secret_automatically_when_api_key_is_saved(): void
    {
        $client = Client::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'active' => true,
        ]);

        $connection = PlatformConnection::query()->create([
            'client_id' => $client->id,
            'platform_type' => 'treble',
            'name' => 'Treble',
            'slug' => 'treble',
            'base_url' => 'https://main.treble.ai',
            'credentials' => ['api_key' => 'api-key'],
            'settings' => [
                'send_path' => '/deployment/api/poll/{poll_id}',
                'auth_mode' => 'bearer_api_key',
            ],
            'signature_header' => 'X-Treble-Webhook-Secret',
            'webhook_secret' => PlatformConnection::generateWebhookSecret(),
            'active' => true,
        ]);

        $this->assertSame('X-Treble-Webhook-Secret', $connection->signature_header);
        $this->assertNotEmpty($connection->webhook_secret);
    }

    private function seedClientAndConnection(): array
    {
        $client = Client::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'active' => true,
        ]);

        $connection = PlatformConnection::query()->create([
            'client_id' => $client->id,
            'platform_type' => 'treble',
            'name' => 'Treble',
            'slug' => 'treble',
            'base_url' => 'https://main.treble.ai',
            'signature_header' => 'X-Treble-Webhook-Secret',
            'webhook_secret' => 'status-secret',
            'credentials' => ['api_key' => 'treble-token'],
            'settings' => [
                'send_path' => '/deployment/api/poll/{poll_id}',
                'auth_mode' => 'bearer_api_key',
            ],
            'active' => true,
        ]);

        return [$client, $connection];
    }
}

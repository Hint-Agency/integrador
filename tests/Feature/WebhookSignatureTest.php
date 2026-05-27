<?php

namespace Tests\Feature;

use App\Jobs\WebhookCustomProcessJob;
use App\Models\Platform;
use App\Services\EventProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\WebhookClient\Models\WebhookCall;
use Tests\TestCase;

class WebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_webhook_with_valid_signature_is_accepted_and_queued(): void
    {
        Queue::fake();

        Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'signature' => 'x-signature',
            'secret_key' => 'webhook-secret',
            'active' => true,
        ]);

        $payload = [
            'subscriptionType' => 'company.created',
            'objectId' => 1001,
        ];

        $rawPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash('sha256', 'webhook-secret' . $rawPayload);

        $response = $this->call(
            'POST',
            '/webhooks/hubspot',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-SIGNATURE' => $signature,
            ],
            $rawPayload
        );

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Webhook received',
        ]);

        $this->assertDatabaseCount('webhook_calls', 1);
        Queue::assertPushed(WebhookCustomProcessJob::class);
    }

    public function test_odoo_webhook_accepts_shared_token_header(): void
    {
        Queue::fake();

        Platform::query()->create([
            'name' => 'Odoo Ambit',
            'slug' => 'odoo-ambit',
            'type' => 'odoo',
            'signature' => 'x-odoo-signature',
            'secret_key' => 'shared-webhook-token',
            'active' => true,
        ]);

        $payload = [
            '_model' => 'account.move',
            'id' => 88,
        ];

        $response = $this->postJson('/webhooks/odoo-ambit', $payload, [
            'x-odoo-signature' => 'shared-webhook-token',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('webhook_calls', 1);
        Queue::assertPushed(WebhookCustomProcessJob::class);
    }

    public function test_odoo_webhook_accepts_singular_webhook_path(): void
    {
        Queue::fake();

        Platform::query()->create([
            'name' => 'Odoo Ambit',
            'slug' => 'odoo-ambit',
            'type' => 'odoo',
            'signature' => 'x-odoo-signature',
            'secret_key' => 'shared-webhook-token',
            'active' => true,
        ]);

        $response = $this->postJson('/webhook/odoo-ambit', [
            '_model' => 'account.move',
            'id' => 88,
        ], [
            'x-odoo-signature' => 'shared-webhook-token',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('webhook_calls', 1);
        Queue::assertPushed(WebhookCustomProcessJob::class);
    }

    public function test_odoo_shared_token_rejects_query_token(): void
    {
        Queue::fake();

        Platform::query()->create([
            'name' => 'Odoo Ambit',
            'slug' => 'odoo-ambit',
            'type' => 'odoo',
            'signature' => 'x-odoo-signature',
            'secret_key' => 'shared-webhook-token',
            'active' => true,
        ]);

        $response = $this->postJson('/webhooks/odoo-ambit?x-odoo-signature=shared-webhook-token', [
            '_model' => 'account.move',
            'id' => 88,
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'status' => 'error',
            'message' => 'Webhook not received, invalid signature.',
        ]);
        $this->assertDatabaseCount('webhook_calls', 0);
        Queue::assertNothingPushed();
    }

    public function test_odoo_shared_token_accepts_query_token_when_enabled(): void
    {
        Queue::fake();

        Platform::query()->create([
            'name' => 'Odoo Ambit',
            'slug' => 'odoo-ambit',
            'type' => 'odoo',
            'signature' => 'x-odoo-signature',
            'secret_key' => 'shared-webhook-token',
            'active' => true,
            'settings' => [
                'webhook' => [
                    'validation_mode' => 'shared_token',
                    'allow_token_in_query' => true,
                ],
            ],
        ]);

        $response = $this->postJson('/webhook/odoo-ambit?x-odoo-signature=shared-webhook-token', [
            '_model' => 'account.move',
            'id' => 88,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('webhook_calls', 1);
        Queue::assertPushed(WebhookCustomProcessJob::class);
    }

    public function test_odoo_shared_token_accepts_query_token_with_accidental_space_in_key_when_enabled(): void
    {
        Queue::fake();

        Platform::query()->create([
            'name' => 'Odoo Ambit',
            'slug' => 'odoo-ambit',
            'type' => 'odoo',
            'signature' => 'x-odoo-signature',
            'secret_key' => 'shared-webhook-token',
            'active' => true,
            'settings' => [
                'webhook' => [
                    'validation_mode' => 'shared_token',
                    'allow_token_in_query' => true,
                ],
            ],
        ]);

        $response = $this->postJson('/webhook/odoo-ambit?api_token%20=shared-webhook-token', [
            '_model' => 'account.move',
            'id' => 88,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('webhook_calls', 1);
        Queue::assertPushed(WebhookCustomProcessJob::class);
    }

    public function test_odoo_webhook_process_job_uses_model_as_subscription_type(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Odoo Ambit',
            'slug' => 'odoo-ambit',
            'type' => 'odoo',
            'active' => true,
        ]);

        $webhookCall = WebhookCall::query()->create([
            'name' => 'odoo-ambit',
            'url' => 'https://integrador.test/webhook/odoo-ambit',
            'headers' => [],
            'payload' => [
                '_model' => 'account.move',
                'id' => 88,
                'state' => 'posted',
            ],
            'exception' => null,
        ]);

        $eventProcessingService = Mockery::mock(EventProcessingService::class);
        $eventProcessingService
            ->shouldReceive('processEvent')
            ->once()
            ->withArgs(fn (string $subscriptionType, array $payload, Platform $resolvedPlatform): bool => $subscriptionType === 'account.move'
                && ($payload['id'] ?? null) === 88
                && $resolvedPlatform->is($platform))
            ->andReturn([
                'success' => true,
                'total_events' => 1,
            ]);

        $this->app->instance(EventProcessingService::class, $eventProcessingService);

        (new WebhookCustomProcessJob($webhookCall))->handle();

        $this->assertTrue(true);
    }
}

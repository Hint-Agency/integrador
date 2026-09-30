<?php

namespace Tests\Feature\Lite;

use App\Models\Client;
use App\Models\PlatformConnection;
use App\Models\TrebleTemplate;
use App\Services\Treble\TrebleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrebleServiceAuthorizationHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_treble_service_can_send_plain_authorization_header(): void
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
            'credentials' => ['api_key' => 'plain-auth-token'],
            'settings' => [
                'send_path' => '/deployment/api/poll/{poll_id}',
                'auth_mode' => 'authorization_header',
                'country_code_default' => '52',
            ],
            'active' => true,
        ]);

        $template = TrebleTemplate::query()->create([
            'client_id' => $client->id,
            'name' => 'Bienvenida',
            'external_template_id' => '1276100',
            'request_template' => [
                'user_session_keys' => [
                    ['key' => 'name', 'value' => 'Carlos'],
                ],
            ],
            'active' => true,
        ]);

        Http::fake([
            'https://main.treble.ai/deployment/api/poll/1276100' => function ($request) {
                $this->assertSame('plain-auth-token', $request->header('Authorization')[0] ?? null);

                return Http::response([
                    'external_id' => 'ext-123',
                ], 200);
            },
        ]);

        $response = app(TrebleService::class)->sendTemplate($connection, $template, [
            'firstname' => 'Carlos',
            'phone' => '+529991412826',
        ]);

        $this->assertTrue($response['success']);
        $this->assertSame('ext-123', $response['external_id']);
    }

    public function test_treble_service_maps_request_template_into_user_session_keys(): void
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
            'credentials' => ['api_key' => 'plain-auth-token'],
            'settings' => [
                'send_path' => '/deployment/api/poll/{poll_id}',
                'auth_mode' => 'authorization_header',
                'country_code_default' => '52',
            ],
            'active' => true,
        ]);

        $template = TrebleTemplate::query()->create([
            'client_id' => $client->id,
            'name' => 'Bienvenida',
            'external_template_id' => '1276100',
            'request_template' => [
                'name' => '{{contact.firstname}}',
                'campus' => '{{contact.campus_de_interes}}',
                'template_id' => '{{template.external_template_id}}',
                'school_level' => '{{contact.nivel_escolar_de_interes}}',
            ],
            'active' => true,
        ]);

        Http::fake([
            'https://main.treble.ai/deployment/api/poll/1276100' => function ($request) {
                $payload = $request->data();
                $sessionKeys = $payload['users'][0]['user_session_keys'] ?? [];

                $this->assertSame([
                    ['key' => 'name', 'value' => 'Carlos'],
                    ['key' => 'campus', 'value' => 'Manzanillo'],
                    ['key' => 'template_id', 'value' => '1276100'],
                    ['key' => 'school_level', 'value' => 'Primaria'],
                ], $sessionKeys);

                return Http::response([
                    'external_id' => 'ext-456',
                ], 200);
            },
        ]);

        $response = app(TrebleService::class)->sendTemplate($connection, $template, [
            'firstname' => 'Carlos',
            'phone' => '+529991412826',
            'campus_de_interes' => 'Manzanillo',
            'nivel_escolar_de_interes' => 'Primaria',
        ]);

        $this->assertTrue($response['success']);
        $this->assertSame('ext-456', $response['external_id']);
    }

    public function test_treble_service_uses_first_conversation_id_as_external_id(): void
    {
        [$connection, $template] = $this->connectionAndTemplate();

        Http::fake([
            '*' => Http::response([
                'id' => 'poll-123',
                'conversations_id' => ['conversation-123'],
                'message' => 'The poll was deployed',
            ], 200),
        ]);

        $response = app(TrebleService::class)->sendTemplate(
            $connection,
            $template,
            ['firstname' => 'Carlos', 'phone' => '+529991412826']
        );

        $this->assertTrue($response['success']);
        $this->assertSame('conversation-123', $response['external_id']);
    }

    public function test_treble_service_reports_duplicate_user_as_retryable_failure(): void
    {
        [$connection, $template] = $this->connectionAndTemplate();

        Http::fake([
            '*' => Http::response([
                'id' => 'poll-123',
                'message' => 'The poll was deployed',
                'duplicate_users' => [[
                    'cellphone' => '9991412826',
                    'country_code' => '+52',
                    'retry_after_seconds' => 53,
                ]],
                'conversations_id' => [],
            ], 200),
        ]);

        $response = app(TrebleService::class)->sendTemplate(
            $connection,
            $template,
            ['firstname' => 'Carlos', 'phone' => '+529991412826']
        );

        $this->assertFalse($response['success']);
        $this->assertTrue($response['retryable']);
        $this->assertSame('duplicate_user', $response['error']['code']);
        $this->assertSame(53, $response['error']['details']['duplicate_users'][0]['retry_after_seconds']);
    }

    private function connectionAndTemplate(): array
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
            'credentials' => ['api_key' => 'plain-auth-token'],
            'settings' => [
                'send_path' => '/deployment/api/poll/{poll_id}',
                'auth_mode' => 'authorization_header',
                'country_code_default' => '52',
            ],
            'active' => true,
        ]);

        $template = TrebleTemplate::query()->create([
            'client_id' => $client->id,
            'name' => 'Bienvenida',
            'external_template_id' => '1276100',
            'request_template' => [
                'name' => '{{contact.firstname}}',
            ],
            'active' => true,
        ]);

        return [$connection, $template];
    }
}

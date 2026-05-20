<?php

namespace Tests\Feature;

use App\Jobs\Generic\EndpointExecutionJob;
use App\Jobs\ProcessNextEventJob;
use App\Models\Event;
use App\Models\EventHttpConfig;
use App\Models\Platform;
use App\Models\Record;
use App\Services\EventLoggingService;
use App\Services\EventProcessingService;
use App\Services\Generic\GenericHttpAdapter;
use App\Services\RateLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class EndpointExecutionJobResponsePropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_next_event_with_destination_response_payload(): void
    {
        Queue::fake();

        $sourcePlatform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $targetPlatform = Platform::query()->create([
            'name' => 'ASPEL',
            'slug' => 'aspel',
            'type' => 'generic',
            'credentials' => [
                'api_key' => 'token_aspel_test',
            ],
            'active' => true,
        ]);

        $nextEvent = Event::query()->create([
            'platform_id' => $sourcePlatform->id,
            'name' => 'Write Back Contact',
            'event_type_id' => 'object.updated',
            'method_name' => 'syncContactExecutionResponse',
            'type' => 'webhook',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $targetPlatform->id,
            'to_event_id' => $nextEvent->id,
            'name' => 'Create Contact',
            'event_type_id' => 'generic.external.call',
            'type' => 'webhook',
            'active' => true,
        ]);

        EventHttpConfig::query()->create([
            'event_id' => $event->id,
            'method' => 'POST',
            'base_url' => 'https://api.example.com',
            'path' => '/contacts',
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'generic.external.call',
            'status' => 'init',
            'payload' => [],
            'message' => 'init',
        ]);

        $payload = [
            'hubspot_object_id' => '401',
            'source_event_id' => 1,
            'emailEnvio' => 'old@example.com',
            'propertyName' => 'lifecyclestage',
        ];

        $response = [
            'success' => true,
            'status_code' => 201,
            'retryable' => false,
            'request_id' => 'req_123',
            'external_id' => 'asp_999',
            'latency_ms' => 25,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/contacts',
            'method' => 'POST',
            'data' => [
                'id' => 'asp_999',
                'emailEnvio' => 'ana@example.com',
                'clave' => '50897',
            ],
            'error' => [
                'code' => null,
                'message' => null,
                'details' => null,
            ],
        ];

        $eventProcessingService = app(EventProcessingService::class);
        $eventLoggingService = app(EventLoggingService::class);
        $rateLimitService = app(RateLimitService::class);

        $httpAdapter = Mockery::mock(GenericHttpAdapter::class);
        $httpAdapter->shouldReceive('send')->once()->andReturn($response);

        $job = new EndpointExecutionJob($event->fresh('platform', 'to_event'), $record, $payload);
        $job->handle($eventProcessingService, $httpAdapter, $eventLoggingService, $rateLimitService);

        Queue::assertPushed(ProcessNextEventJob::class, function (ProcessNextEventJob $job) use ($event, $record): bool {
            return $job->event->id === $event->id
                && $job->record->id === $record->id
                && ($job->data['emailEnvio'] ?? null) === 'ana@example.com'
                && ($job->data['clave'] ?? null) === '50897'
                && ($job->data['destination_response']['external_id'] ?? null) === 'asp_999'
                && ($job->data['destination_execution']['source_event_id'] ?? null) === 1
                && ($job->data['hubspot_object_id'] ?? null) === '401'
                && ! array_key_exists('_event_metadata', $job->data);
        });
    }

    public function test_it_uses_output_payload_as_base_for_next_event_when_present(): void
    {
        Queue::fake();

        $sourcePlatform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $targetPlatform = Platform::query()->create([
            'name' => 'ASPEL',
            'slug' => 'aspel',
            'type' => 'generic',
            'credentials' => [
                'api_key' => 'token_aspel_test',
            ],
            'active' => true,
        ]);

        $nextEvent = Event::query()->create([
            'platform_id' => $targetPlatform->id,
            'name' => 'Create Contact Fallback',
            'event_type_id' => 'generic.external.call',
            'type' => 'webhook',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $targetPlatform->id,
            'to_event_id' => $nextEvent->id,
            'name' => 'Update Contact With Lookup',
            'event_type_id' => 'generic.external.call',
            'type' => 'webhook',
            'active' => true,
        ]);

        EventHttpConfig::query()->create([
            'event_id' => $event->id,
            'method' => 'POST',
            'base_url' => 'https://api.example.com',
            'path' => '/contacts',
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'generic.external.call',
            'status' => 'init',
            'payload' => [],
            'message' => 'init',
        ]);

        $payload = [
            'hubspot_object_id' => '401',
            'source_event_id' => 1,
            'propertyName' => 'lifecyclestage',
        ];

        $response = [
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_456',
            'external_id' => null,
            'latency_ms' => 25,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/contacts',
            'method' => 'POST',
            'data' => [
                'updated_count' => 0,
                'not_found_count' => 1,
                'lookup_criteria' => ['rfc' => 'RFC123'],
                'output_payload' => [
                    'nombre' => 'Demo Integrador',
                    'rfc' => 'RFC123',
                    'emailEnvio' => 'demo@example.com',
                ],
            ],
            'error' => [
                'code' => null,
                'message' => 'ASPEL contact prepared for creation fallback.',
                'details' => null,
            ],
        ];

        $eventProcessingService = app(EventProcessingService::class);
        $eventLoggingService = app(EventLoggingService::class);
        $rateLimitService = app(RateLimitService::class);

        $httpAdapter = Mockery::mock(GenericHttpAdapter::class);
        $httpAdapter->shouldReceive('send')->once()->andReturn($response);

        $job = new EndpointExecutionJob($event->fresh('platform', 'to_event'), $record, $payload);
        $job->handle($eventProcessingService, $httpAdapter, $eventLoggingService, $rateLimitService);

        Queue::assertPushed(ProcessNextEventJob::class, function (ProcessNextEventJob $job) use ($event, $record): bool {
            return $job->event->id === $event->id
                && $job->record->id === $record->id
                && ($job->data['nombre'] ?? null) === 'Demo Integrador'
                && ($job->data['rfc'] ?? null) === 'RFC123'
                && ($job->data['emailEnvio'] ?? null) === 'demo@example.com'
                && ! array_key_exists('lookup_criteria', $job->data)
                && ! array_key_exists('output_payload', $job->data)
                && ($job->data['hubspot_object_id'] ?? null) === '401';
        });
    }
}

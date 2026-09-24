<?php

namespace Tests\Feature;

use App\Jobs\Generic\EndpointExecutionJob;
use App\Jobs\ProcessNextEventJob;
use App\Models\Event;
use App\Models\EventHttpConfig;
use App\Models\EventIdempotencyKey;
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

class EndpointExecutionJobIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_persistent_idempotency_keys_to_skip_duplicate_execution(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Generic',
            'slug' => 'generic',
            'type' => 'generic',
            'credentials' => [
                'api_key' => 'token_123',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'External Call',
            'event_type_id' => 'generic.external.call',
            'type' => 'webhook',
            'active' => true,
        ]);

        EventHttpConfig::query()->create([
            'event_id' => $event->id,
            'method' => 'POST',
            'base_url' => 'https://api.example.com',
            'path' => '/v1/sync',
            'idempotency_config_json' => [
                'enabled' => true,
                'ttl_hours' => 24,
                'key_template' => '{event_id}:{record_id}:{method}:{path}',
            ],
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'generic.external.call',
            'status' => 'init',
            'payload' => ['payload' => ['id' => 1]],
            'message' => 'init',
        ]);

        $eventProcessingService = app(EventProcessingService::class);
        $eventLoggingService = app(EventLoggingService::class);
        $rateLimitService = app(RateLimitService::class);

        $response = [
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req_123',
            'external_id' => 'ext_456',
            'latency_ms' => 100,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com/v1/sync',
            'method' => 'POST',
            'data' => ['ok' => true],
            'error' => [
                'code' => null,
                'message' => null,
                'details' => null,
            ],
        ];

        $httpAdapter = Mockery::mock(GenericHttpAdapter::class);
        $httpAdapter->shouldReceive('send')->once()->andReturn($response);

        $job = new EndpointExecutionJob($event, $record, ['payload' => ['id' => 1]]);
        $job->handle($eventProcessingService, $httpAdapter, $eventLoggingService, $rateLimitService);

        $this->assertDatabaseCount('event_idempotency_keys', 1);
        $this->assertDatabaseHas('event_idempotency_keys', [
            'event_id' => $event->id,
            'record_id' => $record->id,
            'status' => 'success',
        ]);

        $secondAdapter = Mockery::mock(GenericHttpAdapter::class);
        $secondAdapter->shouldNotReceive('send');

        $secondJob = new EndpointExecutionJob($event, $record, ['payload' => ['id' => 1]]);
        $secondJob->handle($eventProcessingService, $secondAdapter, $eventLoggingService, $rateLimitService);

        $record->refresh();
        $idempotency = EventIdempotencyKey::query()->first();

        $this->assertSame('warning', $record->status);
        $this->assertSame('success', $idempotency?->status);
    }

    public function test_it_can_build_idempotency_from_quote_ids_across_different_records(): void
    {
        $platform = Platform::query()->create([
            'name' => 'ASPEL',
            'slug' => 'aspel',
            'type' => 'generic',
            'credentials' => ['api_key' => 'token_123'],
            'settings' => ['service_driver' => 'aspel'],
            'active' => true,
        ]);
        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Create ASPEL Quote',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'createQuote',
            'type' => 'webhook',
            'active' => true,
        ]);
        EventHttpConfig::query()->create([
            'event_id' => $event->id,
            'method' => 'POST',
            'base_url' => 'https://api.example.com',
            'path' => '/api/quotes',
            'idempotency_config_json' => [
                'enabled' => true,
                'ttl_hours' => 24 * 30,
                'key_template' => '{event_id}:{method}:{payload.hubspotDealId}:{payload.hubspotQuoteId}',
            ],
            'active' => true,
        ]);

        $payload = [
            'hubspotDealId' => '123',
            'hubspotQuoteId' => '456',
            'claveCliente' => '39489',
            'correoVendedor' => 'seller@example.com',
            'direccionEnvio' => ['nombre' => 'Cliente', 'calle' => 'Calle real', 'numeroInterior' => '2',
                'numeroExterior' => '100', 'poblacion' => 'Merida', 'referencia' => 'Porton azul'],
            'partidas' => [[
                'hubspotLineItemId' => '789',
                'cveArt' => '10040003',
                'cantidad' => 1,
                'cveAlmacen' => 5,
                'listaPrecio' => 7,
                'precioUnitario' => 5637,
                'iva' => 0,
            ]],
        ];
        $firstRecord = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'generic.external.call',
            'status' => 'init',
            'payload' => $payload,
            'message' => 'init',
        ]);
        $secondRecord = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'generic.external.call',
            'status' => 'init',
            'payload' => $payload,
            'message' => 'init',
        ]);
        $response = [
            'success' => true,
            'status_code' => 201,
            'retryable' => false,
            'data' => ['created' => true, 'cveDoc' => '0000012699'],
        ];

        $firstAdapter = Mockery::mock(GenericHttpAdapter::class);
        $firstAdapter->shouldReceive('send')->once()->andReturn($response);
        (new EndpointExecutionJob($event, $firstRecord, $payload))->handle(
            app(EventProcessingService::class),
            $firstAdapter,
            app(EventLoggingService::class),
            app(RateLimitService::class)
        );

        $secondAdapter = Mockery::mock(GenericHttpAdapter::class);
        $secondAdapter->shouldNotReceive('send');
        (new EndpointExecutionJob($event, $secondRecord, $payload))->handle(
            app(EventProcessingService::class),
            $secondAdapter,
            app(EventLoggingService::class),
            app(RateLimitService::class)
        );

        $this->assertDatabaseCount('event_idempotency_keys', 1);
        $this->assertSame('warning', $secondRecord->fresh()->status);
    }

    public function test_it_preserves_hubspot_quote_context_for_the_next_event(): void
    {
        Queue::fake();

        $platform = Platform::query()->create([
            'name' => 'ASPEL',
            'slug' => 'aspel',
            'type' => 'generic',
            'credentials' => ['api_key' => 'token_123'],
            'settings' => ['service_driver' => 'aspel'],
            'active' => true,
        ]);
        $nextEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Quote write-back',
            'event_type_id' => 'object.updated',
            'method_name' => 'updateQuoteObject',
            'type' => 'webhook',
            'active' => true,
        ]);
        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'to_event_id' => $nextEvent->id,
            'name' => 'Create ASPEL Quote',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'createQuote',
            'type' => 'webhook',
            'active' => true,
        ]);
        EventHttpConfig::query()->create([
            'event_id' => $event->id,
            'method' => 'POST',
            'base_url' => 'https://api.example.com',
            'path' => '/api/quotes',
            'active' => true,
        ]);
        $payload = [
            'hubspotDealId' => '123',
            'hubspotQuoteId' => '456',
            'hubspotLineItemId' => '789',
            'claveCliente' => '39489',
            'correoVendedor' => 'seller@example.com',
            'direccionEnvio' => ['nombre' => 'Cliente', 'calle' => 'Calle real', 'numeroInterior' => '2',
                'numeroExterior' => '100', 'poblacion' => 'Merida', 'referencia' => 'Porton azul'],
            'partidas' => [[
                'hubspotLineItemId' => '789',
                'cveArt' => '10040003',
                'cantidad' => 1,
                'cveAlmacen' => 5,
                'listaPrecio' => 7,
                'precioUnitario' => 5637,
                'iva' => 0,
            ]],
        ];
        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'generic.external.call',
            'status' => 'init',
            'payload' => $payload,
            'message' => 'init',
        ]);
        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->andReturn([
            'success' => true,
            'status_code' => 201,
            'retryable' => false,
            'data' => [
                'created' => true,
                'cveDoc' => '0000012699',
                'folio' => 12699,
            ],
        ]);

        (new EndpointExecutionJob($event, $record, $payload))->handle(
            app(EventProcessingService::class),
            $adapter,
            app(EventLoggingService::class),
            app(RateLimitService::class)
        );

        $this->assertSame('success', $record->fresh()->status);
        Queue::assertPushed(ProcessNextEventJob::class, function (ProcessNextEventJob $job): bool {
            return ($job->data['hubspotDealId'] ?? null) === '123'
                && ($job->data['hubspotQuoteId'] ?? null) === '456'
                && ($job->data['hubspotLineItemId'] ?? null) === '789'
                && ($job->data['cveDoc'] ?? null) === '0000012699'
                && ($job->data['folio'] ?? null) === 12699;
        });
    }
}

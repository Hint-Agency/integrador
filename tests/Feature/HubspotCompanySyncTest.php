<?php

namespace Tests\Feature;

use App\Jobs\ProcessCompanyUpdateJob;
use App\Jobs\ProcessNextEventJob;
use App\Models\Event;
use App\Models\Platform;
use App\Models\Record;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use App\Services\Hubspot\HubspotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class HubspotCompanySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_company_method_creates_company_in_hubspot(): void
    {
        [$platform, $event] = $this->makeHubspotEvent('createCompany', 'company.created');

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('createObject')
            ->once()
            ->with('companies', [
                'identificador_db' => 'ACCT-100',
                'name' => 'Cliente Norte',
            ])
            ->andReturn([
                'success' => true,
                'data' => ['id' => 'cmp-100'],
            ]);

        $service = new HubspotService($platform, $event, null, $hubspotApi);
        $result = $service->createCompany([
            'identificador_db' => 'ACCT-100',
            'name' => 'Cliente Norte',
            'hubspot_object_id' => 'context-only',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('created', data_get($result, 'data.operation'));
        $this->assertSame('cmp-100', data_get($result, 'data.company_id'));
    }

    public function test_create_company_method_accepts_batch_payload_from_previous_event(): void
    {
        [$platform, $event] = $this->makeHubspotEvent('createCompany', 'company.created');

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('createObject')
            ->once()
            ->with('companies', [
                'identificador_db' => 'ACCT-100',
                'name' => 'Cliente Norte',
            ])
            ->andReturn([
                'success' => true,
                'data' => ['id' => 'cmp-100'],
            ]);
        $hubspotApi->shouldReceive('createObject')
            ->once()
            ->with('companies', [
                'identificador_db' => 'ACCT-200',
                'name' => 'Cliente Sur',
            ])
            ->andReturn([
                'success' => true,
                'data' => ['id' => 'cmp-200'],
            ]);

        $service = new HubspotService($platform, $event, null, $hubspotApi);
        $result = $service->createCompany([
            [
                'identificador_db' => 'ACCT-100',
                'name' => 'Cliente Norte',
            ],
            [
                'identificador_db' => 'ACCT-200',
                'name' => 'Cliente Sur',
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, data_get($result, 'data.created_count'));
        $this->assertSame('cmp-100', data_get($result, 'data.created_companies.0.company_id'));
        $this->assertSame('cmp-200', data_get($result, 'data.created_companies.1.company_id'));
    }

    public function test_update_company_resolves_hubspot_id_by_identificador_db_before_updating(): void
    {
        [$platform, $event] = $this->makeHubspotEvent('updateCompany', 'company.updated');

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('companies', 'identificador_db', 'ACCT-100', ['identificador_db', 'name'])
            ->andReturn([
                'success' => true,
                'data' => [
                    'results' => [
                        ['id' => 'cmp-100'],
                    ],
                ],
            ]);
        $hubspotApi->shouldReceive('updateObject')
            ->once()
            ->with('companies', 'cmp-100', [
                'identificador_db' => 'ACCT-100',
                'name' => 'Cliente Norte',
            ])
            ->andReturn([
                'success' => true,
                'data' => ['id' => 'cmp-100'],
            ]);

        $service = new HubspotService($platform, $event, null, $hubspotApi);
        $result = $service->updateCompany([
            'identificador_db' => 'ACCT-100',
            'name' => 'Cliente Norte',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('updated', data_get($result, 'data.operation'));
        $this->assertSame('cmp-100', data_get($result, 'data.company_id'));
        $this->assertSame([], data_get($result, 'data.output_payload'));
    }

    public function test_update_company_resolves_hubspot_id_by_mapped_account_code_before_phone_or_email(): void
    {
        [$platform, $event] = $this->makeHubspotEvent('updateCompany', 'company.updated');

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('companies', 'codigo_unico_por_cuenta', 'CL-000002536', ['codigo_unico_por_cuenta', 'nombre_de_la_cuenta', 'phone', 'email'])
            ->andReturn([
                'success' => true,
                'data' => [
                    'results' => [
                        ['id' => 'cmp-2536'],
                    ],
                ],
            ]);
        $hubspotApi->shouldReceive('updateObject')
            ->once()
            ->with('companies', 'cmp-2536', [
                'codigo_unico_por_cuenta' => 'CL-000002536',
                'nombre_de_la_cuenta' => 'Cliente Real',
                'phone' => '809-959-0400',
                'email' => 'contabilidadeytpc@gmail.com',
            ])
            ->andReturn([
                'success' => true,
                'data' => ['id' => 'cmp-2536'],
            ]);

        $service = new HubspotService($platform, $event, null, $hubspotApi);
        $result = $service->updateCompany([
            'codigo_unico_por_cuenta' => 'CL-000002536',
            'nombre_de_la_cuenta' => 'Cliente Real',
            'phone' => '809-959-0400',
            'email' => 'contabilidadeytpc@gmail.com',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('updated', data_get($result, 'data.operation'));
        $this->assertSame('cmp-2536', data_get($result, 'data.company_id'));
    }

    public function test_company_update_job_dispatches_creation_event_when_company_is_not_found(): void
    {
        Queue::fake();

        $platform = $this->makeHubspotPlatform();

        $createEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Creación de empresa HubSpot',
            'event_type_id' => 'company.created',
            'method_name' => 'createCompany',
            'type' => 'webhook',
            'active' => true,
        ]);

        $updateEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Actualización de empresa HubSpot',
            'event_type_id' => 'company.updated',
            'method_name' => 'updateCompany',
            'type' => 'webhook',
            'to_event_id' => $createEvent->id,
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $updateEvent->id,
            'event_type' => 'company.updated',
            'status' => 'init',
            'payload' => [
                'identificador_db' => 'ACCT-404',
                'name' => 'Cliente Nuevo',
            ],
            'message' => 'init',
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('companies', 'identificador_db', 'ACCT-404', ['identificador_db', 'name'])
            ->andReturn([
                'success' => true,
                'data' => ['results' => []],
            ]);
        $hubspotApi->shouldNotReceive('updateObject');

        $this->app->instance(HubspotApiServiceRefactored::class, $hubspotApi);

        $job = new ProcessCompanyUpdateJob($updateEvent->fresh('platform', 'to_event'), $record, [
            'identificador_db' => 'ACCT-404',
            'name' => 'Cliente Nuevo',
        ]);
        $job->handle(app(\App\Services\EventProcessingService::class), app(\App\Services\EventLoggingService::class));

        Queue::assertPushed(ProcessNextEventJob::class, function (ProcessNextEventJob $job) use ($updateEvent): bool {
            return $job->event->id === $updateEvent->id
                && ($job->data['identificador_db'] ?? null) === 'ACCT-404'
                && ($job->data['name'] ?? null) === 'Cliente Nuevo';
        });

        $record->refresh();
        $this->assertSame('ACCT-404', data_get($record->details, 'output_payload.identificador_db'));
        $this->assertSame('not_found', data_get($record->details, 'service_output.operation'));
    }

    public function test_company_update_job_handles_batch_and_dispatches_only_not_found_companies(): void
    {
        Queue::fake();

        $platform = $this->makeHubspotPlatform();

        $createEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Creación de empresa HubSpot',
            'event_type_id' => 'company.created',
            'method_name' => 'createCompany',
            'type' => 'webhook',
            'active' => true,
        ]);

        $updateEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Actualización de empresa HubSpot',
            'event_type_id' => 'company.updated',
            'method_name' => 'updateCompany',
            'type' => 'webhook',
            'to_event_id' => $createEvent->id,
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $updateEvent->id,
            'event_type' => 'company.updated',
            'status' => 'init',
            'payload' => [
                [
                    'identificador_db' => 'ACCT-100',
                    'name' => 'Cliente Existente',
                ],
                [
                    'identificador_db' => 'ACCT-404',
                    'name' => 'Cliente Nuevo',
                ],
            ],
            'message' => 'init',
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('companies', 'identificador_db', 'ACCT-100', ['identificador_db', 'name'])
            ->andReturn([
                'success' => true,
                'data' => ['results' => [['id' => 'cmp-100']]],
            ]);
        $hubspotApi->shouldReceive('updateObject')
            ->once()
            ->with('companies', 'cmp-100', [
                'identificador_db' => 'ACCT-100',
                'name' => 'Cliente Existente',
            ])
            ->andReturn([
                'success' => true,
                'data' => ['id' => 'cmp-100'],
            ]);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('companies', 'identificador_db', 'ACCT-404', ['identificador_db', 'name'])
            ->andReturn([
                'success' => true,
                'data' => ['results' => []],
            ]);

        $this->app->instance(HubspotApiServiceRefactored::class, $hubspotApi);

        $job = new ProcessCompanyUpdateJob($updateEvent->fresh('platform', 'to_event'), $record, [
            [
                'identificador_db' => 'ACCT-100',
                'name' => 'Cliente Existente',
            ],
            [
                'identificador_db' => 'ACCT-404',
                'name' => 'Cliente Nuevo',
            ],
        ]);
        $job->handle(app(\App\Services\EventProcessingService::class), app(\App\Services\EventLoggingService::class));

        Queue::assertPushed(ProcessNextEventJob::class, function (ProcessNextEventJob $job) use ($updateEvent): bool {
            return $job->event->id === $updateEvent->id
                && count($job->data) === 1
                && ($job->data[0]['identificador_db'] ?? null) === 'ACCT-404'
                && ($job->data[0]['name'] ?? null) === 'Cliente Nuevo';
        });

        $record->refresh();
        $this->assertSame(1, data_get($record->details, 'service_output.updated_count'));
        $this->assertSame(1, data_get($record->details, 'service_output.not_found_count'));
        $this->assertSame('ACCT-404', data_get($record->details, 'output_payload.0.identificador_db'));
    }

    public function test_company_update_job_dispatches_creation_fallback_when_search_fails_and_next_event_exists(): void
    {
        Queue::fake();

        $platform = $this->makeHubspotPlatform();

        $createEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Creación de empresa HubSpot',
            'event_type_id' => 'company.created',
            'method_name' => 'createCompany',
            'type' => 'webhook',
            'active' => true,
        ]);

        $updateEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Actualización de empresa HubSpot',
            'event_type_id' => 'company.updated',
            'method_name' => 'updateCompany',
            'type' => 'webhook',
            'to_event_id' => $createEvent->id,
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $updateEvent->id,
            'event_type' => 'company.updated',
            'status' => 'init',
            'payload' => [
                [
                    'phone' => '809-959-0400',
                    'email' => 'contabilidadeytpc@gmail.com',
                    'nombre_de_la_cuenta' => 'Cliente con búsqueda fallida',
                ],
            ],
            'message' => 'init',
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('companies', 'phone', '809-959-0400', ['phone', 'email', 'nombre_de_la_cuenta'])
            ->andReturn([
                'success' => false,
                'error' => [
                    'status' => 'error',
                    'message' => 'There was a problem with the request.',
                    'correlationId' => '019e8f1e-a4cb-78f8-ac27-032f873eb90c',
                ],
            ]);
        $hubspotApi->shouldNotReceive('updateObject');

        $this->app->instance(HubspotApiServiceRefactored::class, $hubspotApi);

        $job = new ProcessCompanyUpdateJob($updateEvent->fresh('platform', 'to_event'), $record, [
            [
                'phone' => '809-959-0400',
                'email' => 'contabilidadeytpc@gmail.com',
                'nombre_de_la_cuenta' => 'Cliente con búsqueda fallida',
            ],
        ]);
        $job->handle(app(\App\Services\EventProcessingService::class), app(\App\Services\EventLoggingService::class));

        Queue::assertPushed(ProcessNextEventJob::class, function (ProcessNextEventJob $job) use ($updateEvent): bool {
            return $job->event->id === $updateEvent->id
                && count($job->data) === 1
                && ($job->data[0]['phone'] ?? null) === '809-959-0400'
                && ($job->data[0]['_resolution_warning']['reason'] ?? null) === 'hubspot_company_search_failed_create_fallback';
        });

        $record->refresh();
        $this->assertSame('success', $record->status);
        $this->assertSame(1, data_get($record->details, 'service_output.not_found_count'));
        $this->assertSame('hubspot_company_search_failed_create_fallback', data_get($record->details, 'output_payload.0._resolution_warning.reason'));
    }

    private function makeHubspotEvent(string $methodName, string $eventTypeId): array
    {
        $platform = $this->makeHubspotPlatform();

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => $eventTypeId,
            'event_type_id' => $eventTypeId,
            'method_name' => $methodName,
            'type' => 'webhook',
            'active' => true,
        ]);

        return [$platform, $event];
    }

    private function makeHubspotPlatform(): Platform
    {
        return Platform::query()->create([
            'name' => 'Hubspot corripio',
            'slug' => 'hubspot-corripio-'.uniqid(),
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);
    }
}

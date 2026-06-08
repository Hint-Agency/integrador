<?php

namespace Tests\Feature;

use App\Jobs\ProcessNextEventJob;
use App\Jobs\ProcessObjectUpdateJob;
use App\Models\Event;
use App\Models\Platform;
use App\Models\Record;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use App\Services\Hubspot\HubspotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class HubspotContactSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_contact_method_creates_contact_in_hubspot(): void
    {
        [$platform, $event] = $this->makeHubspotEvent('createContact', 'contact.created');

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('createObject')
            ->once()
            ->with('contacts', [
                'identificador_db' => 'CT-100',
                'email' => 'cliente@example.com',
                'firstname' => 'Cliente',
            ])
            ->andReturn([
                'success' => true,
                'data' => ['id' => 'ct-100'],
            ]);

        $service = new HubspotService($platform, $event, null, $hubspotApi);
        $result = $service->createContact([
            'identificador_db' => 'CT-100',
            'email' => 'cliente@example.com',
            'firstname' => 'Cliente',
            'hubspot_object_id' => 'context-only',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('created', data_get($result, 'data.operation'));
        $this->assertSame('ct-100', data_get($result, 'data.contact_id'));
    }

    public function test_update_contact_resolves_hubspot_id_by_email_before_updating(): void
    {
        [$platform, $event] = $this->makeHubspotEvent('updateContact', 'contact.updated');

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('contacts', 'email', 'cliente@example.com', ['email', 'firstname'])
            ->andReturn([
                'success' => true,
                'data' => [
                    'results' => [
                        ['id' => 'ct-100'],
                    ],
                ],
            ]);
        $hubspotApi->shouldReceive('updateObject')
            ->once()
            ->with('contacts', 'ct-100', [
                'email' => 'cliente@example.com',
                'firstname' => 'Cliente',
            ])
            ->andReturn([
                'success' => true,
                'data' => ['id' => 'ct-100'],
            ]);

        $service = new HubspotService($platform, $event, null, $hubspotApi);
        $result = $service->updateContact([
            'email' => 'cliente@example.com',
            'firstname' => 'Cliente',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('updated', data_get($result, 'data.operation'));
        $this->assertSame('ct-100', data_get($result, 'data.contact_id'));
        $this->assertSame([], data_get($result, 'data.output_payload'));
    }

    public function test_contact_update_job_dispatches_creation_event_when_contact_is_not_found(): void
    {
        Queue::fake();

        $platform = $this->makeHubspotPlatform();

        $createEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Creación de contacto HubSpot',
            'event_type_id' => 'contact.created',
            'method_name' => 'createContact',
            'type' => 'webhook',
            'active' => true,
        ]);

        $updateEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Actualización de contacto HubSpot',
            'event_type_id' => 'contact.updated',
            'method_name' => 'updateContact',
            'type' => 'webhook',
            'to_event_id' => $createEvent->id,
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $updateEvent->id,
            'event_type' => 'contact.updated',
            'status' => 'init',
            'payload' => [
                [
                    'email' => 'existente@example.com',
                    'firstname' => 'Existente',
                ],
                [
                    'email' => 'nuevo@example.com',
                    'firstname' => 'Nuevo',
                ],
            ],
            'message' => 'init',
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('contacts', 'email', 'existente@example.com', ['email', 'firstname'])
            ->andReturn([
                'success' => true,
                'data' => ['results' => [['id' => 'ct-100']]],
            ]);
        $hubspotApi->shouldReceive('updateObject')
            ->once()
            ->with('contacts', 'ct-100', [
                'email' => 'existente@example.com',
                'firstname' => 'Existente',
            ])
            ->andReturn([
                'success' => true,
                'data' => ['id' => 'ct-100'],
            ]);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('contacts', 'email', 'nuevo@example.com', ['email', 'firstname'])
            ->andReturn([
                'success' => true,
                'data' => ['results' => []],
            ]);

        $this->app->instance(HubspotApiServiceRefactored::class, $hubspotApi);

        $job = new ProcessObjectUpdateJob($updateEvent->fresh('platform', 'to_event'), $record, [
            [
                'email' => 'existente@example.com',
                'firstname' => 'Existente',
            ],
            [
                'email' => 'nuevo@example.com',
                'firstname' => 'Nuevo',
            ],
        ]);
        $job->handle(app(\App\Services\EventProcessingService::class), app(\App\Services\EventLoggingService::class));

        Queue::assertPushed(ProcessNextEventJob::class, function (ProcessNextEventJob $job) use ($updateEvent): bool {
            return $job->event->id === $updateEvent->id
                && count($job->data) === 1
                && ($job->data[0]['email'] ?? null) === 'nuevo@example.com'
                && ($job->data[0]['firstname'] ?? null) === 'Nuevo';
        });

        $record->refresh();
        $this->assertSame(1, data_get($record->details, 'service_output.updated_count'));
        $this->assertSame(1, data_get($record->details, 'service_output.not_found_count'));
        $this->assertSame('nuevo@example.com', data_get($record->details, 'output_payload.0.email'));
    }

    public function test_contact_update_job_dispatches_creation_fallback_when_search_fails_and_next_event_exists(): void
    {
        Queue::fake();

        $platform = $this->makeHubspotPlatform();

        $createEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Creación de contacto HubSpot',
            'event_type_id' => 'contact.created',
            'method_name' => 'createContact',
            'type' => 'webhook',
            'active' => true,
        ]);

        $updateEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Actualización de contacto HubSpot',
            'event_type_id' => 'contact.updated',
            'method_name' => 'updateContact',
            'type' => 'webhook',
            'to_event_id' => $createEvent->id,
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $updateEvent->id,
            'event_type' => 'contact.updated',
            'status' => 'init',
            'payload' => [
                [
                    'phone' => '809-959-0400',
                    'email' => 'contabilidadeytpc@gmail.com',
                    'firstname' => 'Contacto',
                ],
            ],
            'message' => 'init',
        ]);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('contacts', 'phone', '809-959-0400', ['phone', 'email', 'firstname'])
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

        $job = new ProcessObjectUpdateJob($updateEvent->fresh('platform', 'to_event'), $record, [
            [
                'phone' => '809-959-0400',
                'email' => 'contabilidadeytpc@gmail.com',
                'firstname' => 'Contacto',
            ],
        ]);
        $job->handle(app(\App\Services\EventProcessingService::class), app(\App\Services\EventLoggingService::class));

        Queue::assertPushed(ProcessNextEventJob::class, function (ProcessNextEventJob $job) use ($updateEvent): bool {
            return $job->event->id === $updateEvent->id
                && count($job->data) === 1
                && ($job->data[0]['email'] ?? null) === 'contabilidadeytpc@gmail.com'
                && ($job->data[0]['_resolution_warning']['reason'] ?? null) === 'hubspot_contact_search_failed_create_fallback';
        });

        $record->refresh();
        $this->assertSame('success', $record->status);
        $this->assertSame(1, data_get($record->details, 'service_output.not_found_count'));
        $this->assertSame('hubspot_contact_search_failed_create_fallback', data_get($record->details, 'output_payload.0._resolution_warning.reason'));
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

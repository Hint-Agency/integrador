<?php

namespace Tests\Feature;

use App\Services\EventLoggingService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventLoggingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_init_record(): void
    {
        $service = app(EventLoggingService::class);

        $record = $service->createEventRecord(
            eventType: 'company.created',
            status: 'init',
            payload: ['id' => 1],
            message: 'Record initialized',
        );

        $this->assertDatabaseHas('records', [
            'id' => $record->id,
            'event_type' => 'company.created',
            'status' => 'init',
        ]);
    }

    public function test_it_can_create_a_record_with_details(): void
    {
        $service = app(EventLoggingService::class);

        $record = $service->createEventRecord(
            eventType: 'azure_sql.products.sync',
            status: 'error',
            payload: ['event_id' => 10],
            message: 'Invalid method name for scheduled event.',
            details: [
                'reason' => 'missing_method_name',
                'queue' => 'events',
            ],
        );

        $record->refresh();

        $this->assertSame('missing_method_name', $record->details['reason'] ?? null);
        $this->assertSame('events', $record->details['queue'] ?? null);
    }

    public function test_it_logs_success_status(): void
    {
        $service = app(EventLoggingService::class);

        $record = $service->createEventRecord(
            eventType: 'company.created',
            status: 'init',
            payload: [],
            message: 'Pending',
        );

        $service->logEventSuccess($record, 'Done');

        $this->assertDatabaseHas('records', [
            'id' => $record->id,
            'status' => 'success',
            'message' => 'Done',
        ]);
    }

    public function test_it_logs_error_status(): void
    {
        $service = app(EventLoggingService::class);

        $record = $service->createEventRecord(
            eventType: 'company.created',
            status: 'processing',
            payload: [],
            message: 'Processing',
        );

        $service->logEventError($record, new Exception('Boom'));

        $this->assertDatabaseHas('records', [
            'id' => $record->id,
            'status' => 'error',
            'message' => 'Boom',
        ]);
    }

    public function test_it_logs_warning_status_with_details(): void
    {
        $service = app(EventLoggingService::class);

        $record = $service->createEventRecord(
            eventType: 'object.updated',
            status: 'processing',
            payload: [],
            message: 'Processing',
        );

        $service->logEventWarning($record, 'Event method not available for execution.', [
            'reason' => 'method_not_available',
            'method_name' => null,
        ]);

        $record->refresh();

        $this->assertSame('warning', $record->status);
        $this->assertSame('El método del evento no está disponible para ejecución.', $record->message);
        $this->assertSame('method_not_available', $record->details['reason'] ?? null);
    }
}

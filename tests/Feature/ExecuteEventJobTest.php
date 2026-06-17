<?php

namespace Tests\Feature;

use App\Jobs\ExecuteEventJob;
use App\Models\Event;
use App\Models\Platform;
use App\Services\EventLoggingService;
use App\Services\EventProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecuteEventJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_details_when_a_scheduled_event_has_no_method_name(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Base de datos corripio',
            'slug' => 'corripio-db',
            'type' => 'generic',
            'settings' => [
                'service_driver' => 'azure_sql',
                'host' => 'sql-crm-maco.database.windows.net',
                'port' => '1433',
                'database' => 'DB-CRM',
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Sincronizar productos',
            'event_type_id' => 'azure_sql.products.sync',
            'type' => 'schedule',
            'schedule_expression' => '0 * * * *',
            'method_name' => null,
            'active' => true,
        ]);

        $job = new ExecuteEventJob($event);
        $job->handle(app(EventLoggingService::class), app(EventProcessingService::class));

        $record = $event->records()->latest('id')->first();

        $this->assertNotNull($record);
        $this->assertSame('error', $record->status);
        $this->assertSame('Nombre de método inválido para el evento programado.', $record->message);
        $this->assertSame('missing_method_name', data_get($record->details, 'reason'));
        $this->assertSame('azure_sql.products.sync', data_get($record->details, 'event_type_id'));
        $this->assertSame('events', data_get($record->details, 'queue'));
    }
}

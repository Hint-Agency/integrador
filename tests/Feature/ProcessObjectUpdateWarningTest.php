<?php

namespace Tests\Feature;

use App\Jobs\ProcessObjectUpdateJob;
use App\Models\Event;
use App\Models\Platform;
use App\Models\Record;
use App\Services\EventLoggingService;
use App\Services\EventProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessObjectUpdateWarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_warning_details_and_adds_hubspot_note_when_method_is_missing(): void
    {
        $platform = Platform::query()->create([
            'name' => 'HubSpot Main',
            'slug' => 'hubspot-main',
            'type' => 'hubspot',
            'active' => true,
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'settings' => [
                'base_url' => 'https://api.hubapi.test',
            ],
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Object Updated',
            'event_type_id' => 'object.updated',
            'type' => 'webhook',
            'subscription_type' => 'unknown.propertyChange',
            'method_name' => null,
            'active' => true,
        ]);

        $record = Record::query()->create([
            'event_id' => $event->id,
            'event_type' => 'object.updated',
            'status' => 'init',
            'payload' => [],
            'message' => 'Initial',
            'details' => null,
        ]);

        Http::fake([
            'https://api.hubapi.test/crm/v3/objects/notes' => Http::response([
                'id' => '1001',
            ], 201),
            'https://api.hubapi.test/crm/v3/objects/notes/1001/associations/contact/445566/202' => Http::response([], 204),
            'https://api.hubapi.test/crm/v3/objects/contact/445566/associations/notes/1001/201' => Http::response([], 204),
        ]);

        $job = new ProcessObjectUpdateJob($event, $record, [
            'objectId' => 445566,
            'subscriptionType' => 'unknown.propertyChange',
        ]);

        $job->handle(app(EventProcessingService::class), app(EventLoggingService::class));

        $record->refresh();

        $this->assertSame('warning', $record->status);
        $this->assertSame('El método del evento no está disponible para ejecución.', $record->message);
        $this->assertSame('method_not_available', $record->details['reason'] ?? null);
        $this->assertTrue((bool) ($record->details['hubspot_note']['success'] ?? false));
        $this->assertSame('1001', $record->details['hubspot_note']['note_id'] ?? null);
    }
}

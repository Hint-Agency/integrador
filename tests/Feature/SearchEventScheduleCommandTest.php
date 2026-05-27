<?php

namespace Tests\Feature;

use App\Jobs\ExecuteEventJob;
use App\Models\Event;
use App\Models\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SearchEventScheduleCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_search_event_schedule_dispatches_due_events(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-02-27 12:00:00');

        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Every minute event',
            'event_type_id' => 'generic.external.call',
            'type' => 'schedule',
            'schedule_expression' => '* * * * *',
            'active' => true,
            'method_name' => 'getSignedQuotes',
        ]);

        $this->artisan('events:search-schedule')->assertExitCode(0);

        Queue::assertPushed(ExecuteEventJob::class, function (ExecuteEventJob $job) use ($event): bool {
            return $job->event->id === $event->id;
        });

        $this->assertNotNull($event->refresh()->last_executed_at);
    }

    public function test_search_event_schedule_skips_recently_executed_event(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-02-27 12:00:00');

        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Recently executed',
            'event_type_id' => 'generic.external.call',
            'type' => 'schedule',
            'schedule_expression' => '* * * * *',
            'active' => true,
            'method_name' => 'getSignedQuotes',
            'last_executed_at' => now(),
        ]);

        $this->artisan('events:search-schedule')->assertExitCode(0);
        Queue::assertNotPushed(ExecuteEventJob::class);

    }
}

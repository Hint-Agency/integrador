<?php

namespace App\Console\Commands;

use App\Jobs\ExecuteEventJob;
use App\Models\Event;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SearchEventSchedule extends Command
{
    protected $signature = 'events:search-schedule';

    protected $description = 'Evaluate scheduled events and dispatch execution jobs';

    public function handle(): int
    {
        $events = Event::query()
            ->with(['platform'])
            ->where('type', 'schedule')
            ->where('active', true)
            ->get();

        foreach ($events as $event) {
            if (! $event->schedule_expression) {
                continue;
            }

            try {
                $cron = new CronExpression($event->schedule_expression);
                $shouldExecute = $cron->isDue(Carbon::now())
                    && (! $event->last_executed_at || Carbon::parse($event->last_executed_at)->lt(Carbon::now()->subMinute()));
            } catch (\Throwable $exception) {
                Log::warning('Invalid schedule expression detected', [
                    'event_id' => $event->id,
                    'schedule_expression' => $event->schedule_expression,
                    'error' => $exception->getMessage(),
                ]);
                $this->warn("Invalid schedule expression for event [{$event->id}] {$event->name}");

                continue;
            }

            if ($shouldExecute) {
                $event->update(['last_executed_at' => now()]);
                ExecuteEventJob::dispatch($event)->onQueue('events');
                $this->info("Scheduled event queued: {$event->name}");
            }
        }

        return Command::SUCCESS;
    }
}

<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Record;
use App\Services\EventLoggingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class MatchEntityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 180;

    public function __construct(
        public array $payload,
        public Event $event,
        public Record $record
    ) {
        $this->onQueue('matching');
    }

    public function handle(EventLoggingService $eventLoggingService): void
    {
        $matches = [];

        foreach ((array) Arr::get($this->payload, 'entities', []) as $index => $entity) {
            if (! is_array($entity)) {
                continue;
            }

            $matches[] = [
                'index' => $index,
                'entity_type' => Arr::get($entity, 'entity_type', Arr::get($entity, 'type', 'entity')),
                'match_key' => $this->resolveMatchKey($entity),
                'target_id' => Arr::get($entity, 'target_id')
                    ?? Arr::get($entity, 'odoo_id')
                    ?? Arr::get($entity, 'hubspot_id'),
            ];
        }

        $eventLoggingService->logEventSuccess($this->record, 'Entity matching completed.');
        $this->record->update([
            'details' => [
                ...((array) $this->record->details),
                'matches' => $matches,
                'job' => self::class,
            ],
        ]);
    }

    private function resolveMatchKey(array $entity): ?array
    {
        foreach (['odoo_id', 'hubspot_id', 'sku', 'default_code', 'vat', 'email'] as $key) {
            $value = Arr::get($entity, $key, Arr::get($entity, 'fields.'.$key));
            if (is_scalar($value) && trim((string) $value) !== '') {
                return [
                    'property' => $key,
                    'value' => (string) $value,
                ];
            }
        }

        return null;
    }
}

<?php

namespace App\Jobs;

use App\Models\Platform;
use App\Services\EventLoggingService;
use App\Services\EventProcessingService;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob as SpatieProcessWebhookJob;

class WebhookCustomProcessJob extends SpatieProcessWebhookJob
{
    public $queue = 'webhooks';
    public int $tries = 1;
    public int $backoff = 300;

    public function handle(): void
    {
        $payloads = $this->webhookCall->payload;
        $platformSlug = $this->webhookCall->name;

        $eventLoggingService = app(EventLoggingService::class);
        $eventProcessingService = app(EventProcessingService::class);

        $platform = Platform::query()->where('slug', $platformSlug)->first();
        if (! $platform) {
            $eventLoggingService->createEventRecord(
                'webhook_error',
                'error',
                is_array($payloads) ? $payloads : ['raw_payload' => $payloads],
                "Platform not found for webhook [{$platformSlug}]"
            );
            Log::error('Platform not found for webhook', ['platform' => $platformSlug]);
            return;
        }

        $payloadItems = $this->extractPayloads($payloads);

        foreach ($payloadItems as $index => $payload) {
            if (! is_array($payload)) {
                $eventLoggingService->createEventRecord(
                    'webhook_error',
                    'error',
                    ['raw_payload' => $payload],
                    'Invalid payload format'
                );
                continue;
            }

            $subscriptionType = $payload['subscriptionType'] ?? $payload['subscription_type'] ?? $payload['_model'] ?? null;
            if (! $subscriptionType) {
                $eventLoggingService->createEventRecord(
                    'webhook_error',
                    'error',
                    $payload,
                    'Missing subscription type in webhook payload'
                );
                continue;
            }

            $result = $eventProcessingService->processEvent($subscriptionType, $payload, $platform);

            if (! $result['success']) {
                Log::warning('Webhook payload processing failed', [
                    'subscription_type' => $subscriptionType,
                    'payload_index' => $index,
                    'message' => $result['message'] ?? null,
                ]);
                continue;
            }

            Log::info('Webhook payload processed', [
                'subscription_type' => $subscriptionType,
                'payload_index' => $index,
                'total_events' => $result['total_events'] ?? 0,
            ]);
        }
    }

    /**
     * @return array<int, array<mixed>>
     */
    private function extractPayloads(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        foreach (['data', 'items', 'results', 'objects', 'records', 'entities'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return [$payload];
    }
}

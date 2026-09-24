<?php

namespace App\Jobs\Concerns;

use App\Jobs\ProcessNextEventJob;
use App\Models\Event;
use App\Models\Record;
use App\Services\EventLoggingService;
use App\Services\EventProcessingService;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use Illuminate\Support\Arr;

trait ProcessesEventJob
{
    protected function processEvent(
        EventProcessingService $eventProcessingService,
        EventLoggingService $eventLoggingService,
        Event $event,
        Record $record,
        array $data,
        string $processingMessage
    ): void {
        $record->update([
            'status' => 'processing',
            'message' => $processingMessage,
        ]);

        $serviceClass = $eventProcessingService->getServiceClass($event->platform);
        if (! $serviceClass || ! class_exists($serviceClass)) {
            $details = [
                'reason' => 'service_class_not_found',
                'event_id' => $event->id,
                'event_type_id' => $event->event_type_id,
                'platform_type' => $event->platform?->type,
                'service_class' => $serviceClass,
            ];
            $details['hubspot_note'] = $this->maybeAddHubspotFailureNote(
                $event,
                $record,
                $data,
                'Service class not found for event processing.'
            );

            $eventLoggingService->logEventWarning($record, 'Service class not found for event processing.', $details);
            return;
        }

        $service = app()->make($serviceClass, [
            'platform' => $event->platform,
            'event' => $event,
            'record' => $record,
        ]);
        $methodName = $event->getMethodName() ?? $event->method_name;
        if (! $methodName || ! method_exists($service, $methodName)) {
            $details = [
                'reason' => 'method_not_available',
                'event_id' => $event->id,
                'event_type_id' => $event->event_type_id,
                'platform_type' => $event->platform?->type,
                'service_class' => $serviceClass,
                'method_name' => $methodName,
                'subscription_type' => $event->getSubscriptionType(),
                'available_methods_sample' => $this->availablePublicMethods($service),
            ];
            $details['hubspot_note'] = $this->maybeAddHubspotFailureNote(
                $event,
                $record,
                $data,
                'Event method not available for execution.'
            );

            $eventLoggingService->logEventWarning($record, 'Event method not available for execution.', $details);
            return;
        }

        $result = $this->invokeService($service, $methodName, $data, $event, $record);
        $outputPayload = $this->resolveOutputPayload($result, $data);
        if (is_array(Arr::get($result, 'data.hubspot_note'))) {
            $this->mergeRecordDetails($record, ['hubspot_note' => Arr::get($result, 'data.hubspot_note')]);
        }

        if (! Arr::get($result, 'success', false)) {
            $message = Arr::get($result, 'message', 'Event processing failed.');
            $noteResult = $this->maybeAddHubspotFailureNote($event, $record, $data, $message);
            $this->mergeRecordDetails($record, [
                'service_output' => Arr::get($result, 'data', []),
                'service_message' => Arr::get($result, 'message'),
                'output_payload' => $outputPayload,
            ]);
            $eventLoggingService->logEventError($record, new \Exception($message));
            if (($noteResult['attempted'] ?? false) === true) {
                $this->mergeRecordDetails($record, [
                    'hubspot_note' => $noteResult,
                ]);
            }
            throw new \RuntimeException($message);
        }

        $this->mergeRecordDetails($record, [
            'service_output' => Arr::get($result, 'data', []),
            'service_message' => Arr::get($result, 'message'),
            'output_payload' => $outputPayload,
        ]);

        if (Arr::get($result, 'status') === 'warning') {
            $eventLoggingService->logEventWarning(
                $record,
                Arr::get($result, 'message', 'Event processed with warnings.'),
                Arr::get($result, 'data', [])
            );
        } else {
            $eventLoggingService->logEventSuccess($record, Arr::get($result, 'message', 'Event processed.'));
        }

        if ($event->to_event && $this->shouldDispatchNextPayload($outputPayload)) {
            ProcessNextEventJob::dispatch($event, $record, $outputPayload)->onQueue('events');
        }
    }

    private function resolveOutputPayload(array $result, array $fallback): array
    {
        $outputPayload = Arr::get($result, 'data.output_payload');

        if (is_array($outputPayload)) {
            return $outputPayload;
        }

        $data = Arr::get($result, 'data', []);

        return is_array($data) ? $data : $fallback;
    }

    private function shouldDispatchNextPayload(array $payload): bool
    {
        if ($payload === []) {
            return false;
        }

        if (array_is_list($payload)) {
            return count($payload) > 0;
        }

        return true;
    }

    private function invokeService(object $service, string $methodName, array $data, Event $event, Record $record): array
    {
        $reflection = new \ReflectionMethod($service, $methodName);
        $required = $reflection->getNumberOfRequiredParameters();

        if ($required === 0) {
            $result = $service->{$methodName}();
        } elseif ($required === 1) {
            $result = $service->{$methodName}($data);
        } elseif ($required === 2) {
            $result = $service->{$methodName}($data, $record);
        } else {
            $subscriptionType = $event->getSubscriptionType() ?? $event->name;
            $result = $service->{$methodName}($subscriptionType, $data, $record);
        }

        if (is_array($result)) {
            return $result;
        }

        return [
            'success' => (bool) $result,
            'message' => $result ? 'Event processed.' : 'Event processing failed.',
            'data' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function availablePublicMethods(object $service): array
    {
        $methods = array_filter(
            get_class_methods($service),
            static fn (string $method): bool => ! str_starts_with($method, '__')
        );

        return array_slice(array_values($methods), 0, 15);
    }

    private function maybeAddHubspotFailureNote(Event $event, Record $record, array $data, string $message): array
    {
        if (($event->platform?->type ?? null) !== 'hubspot') {
            return [
                'attempted' => false,
                'reason' => 'platform_not_hubspot',
            ];
        }

        if (($event->meta['object_type'] ?? null) === 'quotes') {
            return ['attempted' => false, 'reason' => 'quote_failure_note_handled_by_service'];
        }

        $contactId = $this->resolveHubspotContactId($data);
        if (! $contactId) {
            return [
                'attempted' => false,
                'reason' => 'hubspot_contact_id_missing',
            ];
        }

        $this->applyHubspotRuntimeConfig($event);

        $noteBody = trim(implode("\n", [
            '[Integrador] Contact sync warning',
            'Event: ' . ($event->name ?: $event->event_type_id),
            'Record: #' . $record->id,
            'Message: ' . $message,
            'Timestamp: ' . now()->toISOString(),
        ]));

        try {
            $response = app(HubspotApiServiceRefactored::class)->addNoteToObject(
                'contacts',
                (string) $contactId,
                $noteBody,
                [
                    'event_id' => $event->id,
                    'record_id' => $record->id,
                    'event_type_id' => $event->event_type_id,
                ]
            );

            return [
                'attempted' => true,
                'success' => (bool) ($response['success'] ?? false),
                'contact_id' => (string) $contactId,
                'status_code' => $response['status_code'] ?? null,
                'note_id' => Arr::get($response, 'data.id'),
                'error' => $response['error'] ?? null,
            ];
        } catch (\Throwable $exception) {
            return [
                'attempted' => true,
                'success' => false,
                'contact_id' => (string) $contactId,
                'error' => [
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
            ];
        }
    }

    private function resolveHubspotContactId(array $data): ?string
    {
        $candidates = [
            Arr::get($data, 'contact.id'),
            Arr::get($data, 'contact.hubspot_id'),
            Arr::get($data, 'hubspot_contact_id'),
            Arr::get($data, 'hubspot_id'),
            Arr::get($data, 'objectId'),
            Arr::get($data, 'id'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function applyHubspotRuntimeConfig(Event $event): void
    {
        $credentials = $event->platform?->credentials ?? [];
        $settings = $event->platform?->settings ?? [];
        $overrides = [];

        $token = $credentials['access_token'] ?? $credentials['api_token'] ?? null;
        if (is_string($token) && trim($token) !== '') {
            $overrides['hubspot.access_token'] = $token;
        }

        $baseUrl = $settings['base_url'] ?? null;
        if (is_string($baseUrl) && trim($baseUrl) !== '') {
            $overrides['hubspot.base_url'] = $baseUrl;
        }

        if (! empty($overrides)) {
            config($overrides);
        }
    }

    private function mergeRecordDetails(Record $record, array $details): void
    {
        $currentDetails = is_array($record->details) ? $record->details : [];
        $record->update([
            'details' => array_replace_recursive($currentDetails, $details),
        ]);
    }
}

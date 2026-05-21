<?php

namespace App\Jobs\HubSpot;

use App\Models\Client;
use App\Models\PlatformConnection;
use App\Models\Record;
use App\Services\EventLoggingService;
use App\Services\Hubspot\HubspotContactSnapshotService;
use App\Services\Lite\ClientPlatformConfigResolver;
use App\Services\Lite\MessageRuleResolver;
use App\Services\Treble\TrebleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessContactPropertyChangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [30, 120, 300];

    public function __construct(
        public Client $client,
        public PlatformConnection $hubspotConnection,
        public array $payload
    ) {
    }

    public function handle(
        EventLoggingService $eventLoggingService,
        HubspotContactSnapshotService $hubspotContactSnapshotService,
        MessageRuleResolver $messageRuleResolver,
        ClientPlatformConfigResolver $configResolver,
        TrebleService $trebleService
    ): void {
        $record = $eventLoggingService->createEventRecord(
            'contact.propertyChange',
            'init',
            $this->payload,
            'HubSpot contact property change received.',
            null,
            null,
            $this->client->id
        );

        $subscriptionType = (string) ($this->payload['subscriptionType'] ?? $this->payload['subscription_type'] ?? '');
        $triggerProperty = (string) ($this->payload['propertyName'] ?? $this->payload['property_name'] ?? '');
        $triggerValue = $this->payload['propertyValue'] ?? $this->payload['property_value'] ?? null;
        $hubspotObjectId = (string) ($this->payload['objectId'] ?? $this->payload['object_id'] ?? '');

        if (strtolower($subscriptionType) !== 'contact.propertychange') {
            $eventLoggingService->logEventWarning($record, 'Unsupported HubSpot subscription type.', [
                'reason' => 'unsupported_subscription_type',
                'subscription_type' => $subscriptionType,
            ]);
            return;
        }

        if ($triggerProperty === '' || $hubspotObjectId === '') {
            $eventLoggingService->logEventWarning($record, 'Missing trigger property or HubSpot object id.', [
                'reason' => 'missing_context',
                'subscription_type' => $subscriptionType,
                'hubspot_object_id' => $hubspotObjectId,
            ]);
            return;
        }

        $requiredProperties = $this->resolveRequiredProperties();
        $contactResponse = $hubspotContactSnapshotService->fetchContact($this->client->id, $hubspotObjectId, $requiredProperties);

        if (! ($contactResponse['success'] ?? false)) {
            $record->update([
                'status' => 'error',
                'message' => $contactResponse['message'] ?? 'HubSpot snapshot failed.',
                'details' => [
                    'client_id' => $this->client->id,
                    'hubspot_object_id' => $hubspotObjectId,
                    'trigger_property' => $triggerProperty,
                    'trigger_value' => $triggerValue,
                    'hubspot_error' => $contactResponse['error'] ?? null,
                ],
            ]);
            return;
        }

        $contact = $contactResponse['data'] ?? [];
        $contactProperties = $contact['properties'] ?? [];
        $rule = $messageRuleResolver->resolve($this->client->id, $contactProperties, $triggerProperty, $triggerValue);

        if (! $rule || ! $rule->trebleTemplate || ! $rule->trebleTemplate->active) {
            $eventLoggingService->logEventWarning($record, 'No active message rule matched this contact.', [
                'client_id' => $this->client->id,
                'hubspot_object_id' => $hubspotObjectId,
                'trigger_property' => $triggerProperty,
                'trigger_value' => $triggerValue,
                'contact_properties' => $contactProperties,
            ]);
            return;
        }

        try {
            $trebleConnection = $configResolver->forClientAndPlatform($this->client->id, 'treble');
            $trebleResponse = $trebleService->sendTemplate($trebleConnection, $rule->trebleTemplate, $contactProperties, [
                'hubspot_object_id' => $hubspotObjectId,
                'trigger_property' => $triggerProperty,
                'trigger_value' => $triggerValue,
                'contact' => $contact,
            ]);
        } catch (Throwable $exception) {
            $trebleResponse = [
                'success' => false,
                'status_code' => 0,
                'retryable' => false,
                'request_id' => null,
                'external_id' => null,
                'data' => [],
                'error' => [
                    'code' => 'configuration_error',
                    'message' => $exception->getMessage(),
                    'details' => ['exception' => get_class($exception)],
                ],
            ];
        }

        $details = [
            'client_id' => $this->client->id,
            'hubspot_object_id' => $hubspotObjectId,
            'trigger_property' => $triggerProperty,
            'trigger_value' => $triggerValue,
            'matched_rule_id' => $rule->id,
            'matched_rule_name' => $rule->name,
            'treble_template_id' => $rule->trebleTemplate->external_template_id,
            'treble_request' => [
                'poll_id' => $rule->trebleTemplate->external_template_id,
                'template_name' => $rule->trebleTemplate->name,
                'phone' => preg_replace('/\D+/', '', (string) ($contactProperties['phone'] ?? $contactProperties['mobilephone'] ?? '')) ?: null,
                'phone_normalized' => $this->normalizePhoneForTreble(
                    (string) ($contactProperties['phone'] ?? $contactProperties['mobilephone'] ?? ''),
                    (string) ($trebleConnection->settings['country_code_default'] ?? '52')
                ),
                'country_code' => preg_replace('/\D+/', '', (string) ($trebleConnection->settings['country_code_default'] ?? '52')) ?: '52',
            ],
            'treble_response' => $trebleResponse,
            'contact_properties' => $contactProperties,
            'hubspot_note' => null,
        ];

        if ($trebleResponse['success'] ?? false) {
            $record->update([
                'status' => 'success',
                'message' => 'Treble template dispatched successfully.',
                'details' => $details,
            ]);
            return;
        }

        $hubspotNote = $hubspotContactSnapshotService->addContactNote(
            $this->hubspotConnection,
            $hubspotObjectId,
            'Treble dispatch failed for this contact.',
            [
                'rule' => $rule->name,
                'template_id' => $rule->trebleTemplate->external_template_id,
                'status_code' => $trebleResponse['status_code'] ?? null,
                'error' => $trebleResponse['error']['message'] ?? null,
            ]
        );
        $details['hubspot_note'] = $hubspotNote;

        $record->update([
            'status' => 'error',
            'message' => $trebleResponse['error']['message'] ?? 'Treble dispatch failed.',
            'details' => $details,
        ]);
    }

    private function resolveRequiredProperties(): array
    {
        $defaults = [
            'firstname',
            'lastname',
            'phone',
            'mobilephone',
            'campus_de_interes',
            'nivel_escolar_de_interes',
            'plantilla_de_whatsapp',
        ];

        $configured = $this->hubspotConnection->settings['contact_properties'] ?? [];
        if (! is_array($configured) || $configured === []) {
            return $defaults;
        }

        return array_values(array_unique(array_merge($defaults, $configured)));
    }

    private function normalizePhoneForTreble(string $phone, string $countryCode = '52'): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $normalizedCountryCode = preg_replace('/\D+/', '', $countryCode) ?: '52';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, $normalizedCountryCode)) {
            $digits = substr($digits, strlen($normalizedCountryCode));
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        $digits = ltrim($digits, '0');

        return $digits !== '' ? $digits : null;
    }
}

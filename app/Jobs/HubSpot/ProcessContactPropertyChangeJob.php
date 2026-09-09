<?php

namespace App\Jobs\HubSpot;

use App\Models\Client;
use App\Models\PlatformConnection;
use App\Services\EventLoggingService;
use App\Services\Hubspot\HubspotContactSnapshotService;
use App\Services\Hubspot\HubspotOwnerAssignmentService;
use App\Services\Lite\AutomationFlowResolver;
use App\Services\Lite\ClientPlatformConfigResolver;
use App\Services\Lite\MessageRuleResolver;
use App\Services\Treble\TrebleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
    ) {}

    public function middleware(): array
    {
        $objectId = (string) ($this->payload['objectId'] ?? $this->payload['object_id'] ?? 'unknown');

        return [
            (new WithoutOverlapping("hubspot-contact:{$this->client->id}:{$objectId}"))
                ->releaseAfter(10)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(
        EventLoggingService $eventLoggingService,
        HubspotContactSnapshotService $hubspotContactSnapshotService,
        HubspotOwnerAssignmentService $hubspotOwnerAssignmentService,
        AutomationFlowResolver $automationFlowResolver,
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

        $requiredProperties = $this->resolveRequiredProperties(
            $messageRuleResolver,
            $automationFlowResolver,
            $triggerProperty
        );
        $contactResponse = $hubspotContactSnapshotService->fetchContact(
            $this->client->id,
            $hubspotObjectId,
            $requiredProperties
        );

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
        $flow = $automationFlowResolver->resolve(
            $this->client->id,
            $contactProperties,
            $triggerProperty,
            $triggerValue
        );
        $messageRule = $flow === null
            ? $messageRuleResolver->resolve(
                $this->client->id,
                $contactProperties,
                $triggerProperty,
                $triggerValue
            )
            : null;

        if (! $flow && ! $messageRule) {
            $eventLoggingService->logEventWarning($record, 'No active automation rule matched this contact.', [
                'client_id' => $this->client->id,
                'hubspot_object_id' => $hubspotObjectId,
                'trigger_property' => $triggerProperty,
                'trigger_value' => $triggerValue,
                'contact_properties' => $contactProperties,
            ]);

            return;
        }

        $details = [
            'client_id' => $this->client->id,
            'hubspot_object_id' => $hubspotObjectId,
            'trigger_property' => $triggerProperty,
            'trigger_value' => $triggerValue,
            'matched_flow_id' => $flow?->id,
            'matched_flow_name' => $flow?->name,
            'matched_rule_id' => $messageRule?->id,
            'matched_rule_name' => $messageRule?->name,
            'contact_properties' => $contactProperties,
            'owner_assignment' => null,
            'treble_template_id' => null,
            'treble_request' => null,
            'treble_response' => null,
            'hubspot_note' => null,
        ];

        if ($flow) {
            $ownerProperty = trim((string) ($flow->owner_property ?: 'hubspot_owner_id'));
            $currentOwnerId = trim((string) ($contactProperties[$ownerProperty] ?? ''));

            if (! $flow->owner_assignment_enabled) {
                $details['existing_owner_behavior'] = null;
                $details['owner_assignment'] = [
                    'strategy' => null,
                    'selected_owner_id' => $currentOwnerId !== '' ? $currentOwnerId : null,
                    'property' => $ownerProperty,
                    'skipped' => true,
                    'reason' => 'owner_assignment_disabled',
                    'response' => [
                        'success' => true,
                        'status_code' => null,
                        'owner_id' => $currentOwnerId !== '' ? $currentOwnerId : null,
                        'owner_property' => $ownerProperty,
                        'data' => [],
                        'error' => null,
                    ],
                ];

                if ($currentOwnerId === '') {
                    $eventLoggingService->logEventWarning(
                        $record,
                        'Owner assignment was skipped, but the contact has no existing owner.',
                        array_merge($details, ['reason' => 'missing_required_existing_owner'])
                    );

                    return;
                }
            } else {
                $existingOwnerBehavior = $flow->existing_owner_behavior === 'continue' ? 'continue' : 'stop';
                $details['existing_owner_behavior'] = $existingOwnerBehavior;

                if ($currentOwnerId !== '') {
                    $details['owner_assignment'] = [
                        'strategy' => null,
                        'selected_owner_id' => $currentOwnerId,
                        'property' => $ownerProperty,
                        'skipped' => true,
                        'reason' => 'existing_owner_preserved',
                        'response' => [
                            'success' => true,
                            'status_code' => null,
                            'owner_id' => $currentOwnerId,
                            'owner_property' => $ownerProperty,
                            'data' => [],
                            'error' => null,
                        ],
                    ];

                    if ($existingOwnerBehavior === 'stop') {
                        $eventLoggingService->logEventWarning(
                            $record,
                            'The contact already has an owner and the flow is configured to stop.',
                            array_merge($details, ['reason' => 'existing_owner_stopped_flow'])
                        );

                        return;
                    }
                } else {
                    $selectedOwnerId = $hubspotOwnerAssignmentService->selectOwner($flow);

                    if ($selectedOwnerId === null) {
                        $ownerResponse = [
                            'success' => false,
                            'status_code' => 0,
                            'owner_id' => null,
                            'owner_property' => $ownerProperty,
                            'data' => [],
                            'error' => [
                                'message' => 'No eligible HubSpot owner is configured for this flow.',
                                'details' => [],
                            ],
                        ];
                    } else {
                        try {
                            $ownerResponse = $hubspotOwnerAssignmentService->assignOwner(
                                $this->hubspotConnection,
                                $hubspotObjectId,
                                $ownerProperty,
                                $selectedOwnerId
                            );
                        } catch (Throwable $exception) {
                            $ownerResponse = [
                                'success' => false,
                                'status_code' => 0,
                                'owner_id' => $selectedOwnerId,
                                'owner_property' => $ownerProperty,
                                'data' => [],
                                'error' => [
                                    'message' => $exception->getMessage(),
                                    'details' => ['exception' => get_class($exception)],
                                ],
                            ];
                        }
                    }

                    $details['owner_assignment'] = [
                        'strategy' => $flow->owner_selection_strategy ?: 'random',
                        'selected_owner_id' => $selectedOwnerId,
                        'property' => $ownerProperty,
                        'skipped' => false,
                        'reason' => null,
                        'response' => $ownerResponse,
                    ];

                    if (! ($ownerResponse['success'] ?? false)) {
                        $details['hubspot_note'] = $hubspotContactSnapshotService->addContactNote(
                            $this->hubspotConnection,
                            $hubspotObjectId,
                            'Automatic owner assignment failed for this contact.',
                            [
                                'flow' => $flow->name,
                                'owner_id' => $selectedOwnerId,
                                'status_code' => $ownerResponse['status_code'] ?? null,
                                'error' => $ownerResponse['error']['message'] ?? null,
                            ]
                        );

                        $record->update([
                            'status' => 'error',
                            'message' => $ownerResponse['error']['message'] ?? 'HubSpot owner assignment failed.',
                            'details' => $details,
                        ]);

                        return;
                    }

                    $contactProperties[$ownerProperty] = $selectedOwnerId;
                    $details['contact_properties'] = $contactProperties;
                }
            }

            if (! $flow->continue_to_treble) {
                $record->update([
                    'status' => 'success',
                    'message' => ! $flow->owner_assignment_enabled
                        ? 'Owner assignment skipped. The flow has no Treble step.'
                        : ($currentOwnerId !== ''
                        ? 'Existing HubSpot owner preserved. The flow has no Treble step.'
                        : 'HubSpot owner assigned successfully. The flow has no Treble step.'),
                    'details' => $details,
                ]);

                return;
            }

            $messageRule = $messageRuleResolver->resolveForFlow($flow, $contactProperties);
        }

        if (! $messageRule) {
            $eventLoggingService->logEventWarning($record, 'Owner step completed, but no Treble rule matched the contact.', array_merge($details, [
                'reason' => 'no_treble_rule_matched',
            ]));

            return;
        }

        $details['matched_rule_id'] = $messageRule->id;
        $details['matched_rule_name'] = $messageRule->name;

        if (! $messageRule->trebleTemplate || ! $messageRule->trebleTemplate->active) {
            $eventLoggingService->logEventWarning($record, 'The matched rule has no active Treble template.', array_merge($details, [
                'reason' => 'missing_active_treble_template',
            ]));

            return;
        }

        $trebleConnection = null;
        try {
            $trebleConnection = $configResolver->forClientAndPlatform($this->client->id, 'treble');
            $trebleResponse = $trebleService->sendTemplate($trebleConnection, $messageRule->trebleTemplate, $contactProperties, [
                'hubspot_object_id' => $hubspotObjectId,
                'trigger_property' => $triggerProperty,
                'trigger_value' => $triggerValue,
                'contact' => array_merge($contact, ['properties' => $contactProperties]),
                'owner_assignment' => $details['owner_assignment'],
                'automation_flow_id' => $flow?->id,
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

        $countryCode = preg_replace(
            '/\D+/',
            '',
            (string) ($trebleConnection?->settings['country_code_default'] ?? '52')
        ) ?: '52';
        $details['treble_template_id'] = $messageRule->trebleTemplate->external_template_id;
        $details['treble_request'] = [
            'poll_id' => $messageRule->trebleTemplate->external_template_id,
            'template_name' => $messageRule->trebleTemplate->name,
            'phone' => preg_replace('/\D+/', '', (string) ($contactProperties['phone'] ?? $contactProperties['mobilephone'] ?? '')) ?: null,
            'phone_normalized' => $this->normalizePhoneForTreble(
                (string) ($contactProperties['phone'] ?? $contactProperties['mobilephone'] ?? ''),
                $countryCode
            ),
            'country_code' => $countryCode,
        ];
        $details['treble_response'] = $trebleResponse;

        if ($trebleResponse['success'] ?? false) {
            $successMessage = match (true) {
                $flow === null => 'Treble template dispatched successfully.',
                ! $flow->owner_assignment_enabled => 'Owner assignment skipped and Treble template dispatched successfully.',
                (bool) ($details['owner_assignment']['skipped'] ?? false) => 'Existing HubSpot owner preserved and Treble template dispatched successfully.',
                default => 'HubSpot owner assigned and Treble template dispatched successfully.',
            };

            $record->update([
                'status' => 'success',
                'message' => $successMessage,
                'details' => $details,
            ]);

            return;
        }

        $details['hubspot_note'] = $hubspotContactSnapshotService->addContactNote(
            $this->hubspotConnection,
            $hubspotObjectId,
            'Treble dispatch failed for this contact.',
            [
                'rule' => $messageRule->name,
                'template_id' => $messageRule->trebleTemplate->external_template_id,
                'status_code' => $trebleResponse['status_code'] ?? null,
                'error' => $trebleResponse['error']['message'] ?? null,
            ]
        );

        $record->update([
            'status' => 'error',
            'message' => $trebleResponse['error']['message'] ?? 'Treble dispatch failed.',
            'details' => $details,
        ]);
    }

    private function resolveRequiredProperties(
        MessageRuleResolver $messageRuleResolver,
        AutomationFlowResolver $automationFlowResolver,
        string $triggerProperty
    ): array {
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
        $configured = is_array($configured) ? $configured : [];
        $ruleProperties = array_merge(
            $messageRuleResolver->requiredProperties($this->client->id, $triggerProperty),
            $automationFlowResolver->requiredProperties($this->client->id, $triggerProperty)
        );

        return array_values(array_unique(array_merge($defaults, $configured, $ruleProperties)));
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

<?php

namespace App\Services\Lite;

use App\Models\AutomationFlow;

class AutomationFlowResolver
{
    public function __construct(private MessageRuleResolver $conditionResolver) {}

    public function resolve(
        int $clientId,
        array $contactProperties,
        string $triggerProperty,
        mixed $triggerValue
    ): ?AutomationFlow {
        $flows = AutomationFlow::query()
            ->with('owners')
            ->where('client_id', $clientId)
            ->where('active', true)
            ->where('trigger_property', $triggerProperty)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        foreach ($flows as $flow) {
            if (trim((string) ($flow->trigger_value ?? '')) !== trim((string) ($triggerValue ?? ''))) {
                continue;
            }

            if ($this->conditionResolver->matchesConditions($flow->conditions, $contactProperties)) {
                return $flow;
            }
        }

        return null;
    }

    public function requiredProperties(int $clientId, string $triggerProperty): array
    {
        $flows = AutomationFlow::query()
            ->where('client_id', $clientId)
            ->where('active', true)
            ->where('trigger_property', $triggerProperty)
            ->get(['conditions', 'owner_property']);

        $properties = collect([$triggerProperty]);

        foreach ($flows as $flow) {
            $conditions = $this->conditionResolver->normalizeConditions($flow->conditions);

            foreach ($conditions['groups'] as $group) {
                foreach ($group['rules'] ?? [] as $condition) {
                    $properties->push($condition['property'] ?? null);
                }
            }

            $properties->push($flow->owner_property ?: 'hubspot_owner_id');
        }

        return $properties
            ->filter(fn (mixed $property): bool => is_string($property) && trim($property) !== '')
            ->map(fn (string $property): string => trim($property))
            ->unique()
            ->values()
            ->all();
    }
}

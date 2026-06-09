<?php

namespace App\Services\Lite;

use App\Models\MessageRule;

class MessageRuleResolver
{
    public function resolve(int $clientId, array $contactProperties, string $triggerProperty, mixed $triggerValue): ?MessageRule
    {
        $rules = MessageRule::query()
            ->with('trebleTemplate')
            ->where('client_id', $clientId)
            ->where('active', true)
            ->where('trigger_property', $triggerProperty)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if (! $this->matchesValue($rule->trigger_value, $triggerValue)) {
                continue;
            }

            $conditions = $this->normalizeConditions($rule->conditions);

            if ($this->matchesConditionSet($conditions, $contactProperties)) {
                return $rule;
            }
        }

        return null;
    }

    private function matchesValue(mixed $expectedValue, mixed $actualValue): bool
    {
        return trim((string) ($expectedValue ?? '')) === trim((string) ($actualValue ?? ''));
    }

    private function matchesConditionSet(array $conditions, array $contactProperties): bool
    {
        $groups = $conditions['groups'] ?? [];
        if ($groups === []) {
            return true;
        }

        $groupResults = array_map(
            fn (array $group): bool => $this->matchesGroup($group, $contactProperties),
            $groups
        );

        return $this->combineMatches($groupResults, $conditions['match'] ?? 'all');
    }

    private function matchesGroup(array $group, array $contactProperties): bool
    {
        $rules = $group['rules'] ?? [];
        if ($rules === []) {
            return true;
        }

        $ruleResults = array_map(function (array $rule) use ($contactProperties): bool {
            $property = trim((string) ($rule['property'] ?? ''));
            if ($property === '') {
                return true;
            }

            $operator = $this->normalizeMatchOperator($rule['operator'] ?? 'equals');
            $actualValue = $contactProperties[$property] ?? null;

            return $this->matchesOperator($operator, $rule['value'] ?? null, $actualValue);
        }, $rules);

        return $this->combineMatches($ruleResults, $group['match'] ?? 'all');
    }

    private function combineMatches(array $results, string $match): bool
    {
        $mode = $this->normalizeMatchMode($match);

        return $mode === 'any'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    private function matchesOperator(string $operator, mixed $expectedValue, mixed $actualValue): bool
    {
        $expected = trim((string) ($expectedValue ?? ''));
        $actual = trim((string) ($actualValue ?? ''));

        return match ($operator) {
            'not_equals' => $expected !== $actual,
            'contains' => $expected !== '' && str_contains(mb_strtolower($actual), mb_strtolower($expected)),
            'not_contains' => $expected === '' || ! str_contains(mb_strtolower($actual), mb_strtolower($expected)),
            'in' => in_array($actual, $this->parseListValue($expectedValue), true),
            'not_in' => ! in_array($actual, $this->parseListValue($expectedValue), true),
            'is_empty' => $actual === '',
            'is_not_empty' => $actual !== '',
            default => $expected === $actual,
        };
    }

    private function parseListValue(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                fn (mixed $item): string => trim((string) $item),
                $value
            ), fn (string $item): bool => $item !== ''));
        }

        return array_values(array_filter(array_map(
            fn (string $item): string => trim($item),
            explode(',', (string) $value)
        ), fn (string $item): bool => $item !== ''));
    }

    private function normalizeConditions(mixed $conditions): array
    {
        if (! is_array($conditions)) {
            return [
                'match' => 'all',
                'groups' => [],
            ];
        }

        if (isset($conditions['groups']) && is_array($conditions['groups'])) {
            return [
                'match' => $this->normalizeMatchMode($conditions['match'] ?? 'all'),
                'groups' => $this->normalizeGroups($conditions['groups']),
            ];
        }

        if (isset($conditions['rules']) && is_array($conditions['rules'])) {
            return [
                'match' => 'all',
                'groups' => [[
                    'match' => $this->normalizeMatchMode($conditions['match'] ?? 'all'),
                    'rules' => $this->normalizeRules($conditions['rules']),
                ]],
            ];
        }

        if (array_is_list($conditions)) {
            return [
                'match' => 'all',
                'groups' => [[
                    'match' => 'all',
                    'rules' => $this->normalizeRules($conditions),
                ]],
            ];
        }

        return [
            'match' => 'all',
            'groups' => [[
                'match' => 'all',
                'rules' => collect($conditions)
                    ->map(fn (mixed $value, string|int $property): array => [
                        'property' => (string) $property,
                        'operator' => 'equals',
                        'value' => $value,
                    ])->values()->all(),
            ]],
        ];
    }

    private function normalizeGroups(array $groups): array
    {
        return collect($groups)
            ->map(function (mixed $group): ?array {
                if (! is_array($group)) {
                    return null;
                }

                return [
                    'match' => $this->normalizeMatchMode($group['match'] ?? 'all'),
                    'rules' => $this->normalizeRules($group['rules'] ?? []),
                ];
            })
            ->filter(fn (?array $group): bool => $group !== null && ($group['rules'] ?? []) !== [])
            ->values()
            ->all();
    }

    private function normalizeRules(array $rules): array
    {
        return collect($rules)
            ->map(function (mixed $rule): ?array {
                if (! is_array($rule)) {
                    return null;
                }

                $property = trim((string) ($rule['property'] ?? ''));
                if ($property === '') {
                    return null;
                }

                return [
                    'property' => $property,
                    'operator' => $this->normalizeMatchOperator($rule['operator'] ?? 'equals'),
                    'value' => $rule['value'] ?? null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeMatchMode(mixed $match): string
    {
        return trim((string) $match) === 'any' ? 'any' : 'all';
    }

    private function normalizeMatchOperator(mixed $operator): string
    {
        $allowed = [
            'equals',
            'not_equals',
            'contains',
            'not_contains',
            'in',
            'not_in',
            'is_empty',
            'is_not_empty',
        ];

        $normalized = trim((string) $operator);

        return in_array($normalized, $allowed, true)
            ? $normalized
            : 'equals';
    }
}

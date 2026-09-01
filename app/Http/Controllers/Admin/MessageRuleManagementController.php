<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AutomationFlow;
use App\Models\Client;
use App\Models\MessageRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MessageRuleManagementController extends Controller
{
    public function store(Request $request, Client $client, AutomationFlow $flow): RedirectResponse
    {
        abort_unless($flow->client_id === $client->id, 404);
        $data = $this->validatePayload($request, $client);

        MessageRule::query()->create([
            'client_id' => $client->id,
            'automation_flow_id' => $flow->id,
            'treble_template_id' => $data['treble_template_id'],
            'name' => $data['name'],
            'priority' => (int) ($data['priority'] ?? 100),
            'trigger_property' => $flow->trigger_property,
            'trigger_value' => $flow->trigger_value,
            'conditions' => $this->normalizeConditions($data['conditions'] ?? []),
            'active' => (bool) ($data['active'] ?? true),
        ]);

        return redirect()->route('admin.clients.flows.edit', [$client, $flow])
            ->with('success', 'Regla Treble creada correctamente.');
    }

    public function update(
        Request $request,
        Client $client,
        AutomationFlow $flow,
        MessageRule $rule
    ): RedirectResponse {
        abort_unless(
            $flow->client_id === $client->id && $rule->automation_flow_id === $flow->id,
            404
        );
        $data = $this->validatePayload($request, $client);

        $rule->update([
            'treble_template_id' => $data['treble_template_id'],
            'name' => $data['name'],
            'priority' => (int) ($data['priority'] ?? 100),
            'trigger_property' => $flow->trigger_property,
            'trigger_value' => $flow->trigger_value,
            'conditions' => $this->normalizeConditions($data['conditions'] ?? []),
            'active' => (bool) ($data['active'] ?? false),
        ]);

        return redirect()->route('admin.clients.flows.edit', [$client, $flow])
            ->with('success', 'Regla Treble actualizada correctamente.');
    }

    public function destroy(Client $client, AutomationFlow $flow, MessageRule $rule): RedirectResponse
    {
        abort_unless(
            $flow->client_id === $client->id && $rule->automation_flow_id === $flow->id,
            404
        );
        $rule->delete();

        return redirect()->route('admin.clients.flows.edit', [$client, $flow])
            ->with('success', 'Regla Treble eliminada correctamente.');
    }

    private function validatePayload(Request $request, Client $client): array
    {
        return $request->validate([
            'treble_template_id' => [
                'required',
                Rule::exists('treble_templates', 'id')->where(fn ($query) => $query->where('client_id', $client->id)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'integer'],
            'conditions' => ['sometimes', 'array'],
            'conditions.match' => ['nullable', Rule::in(['all', 'any'])],
            'conditions.groups' => ['sometimes', 'array'],
            'conditions.groups.*.match' => ['nullable', Rule::in(['all', 'any'])],
            'conditions.groups.*.rules' => ['sometimes', 'array'],
            'conditions.groups.*.rules.*.property' => ['nullable', 'string', 'max:255'],
            'conditions.groups.*.rules.*.operator' => ['nullable', Rule::in(['equals', 'not_equals', 'contains', 'not_contains', 'in', 'not_in', 'is_empty', 'is_not_empty'])],
            'conditions.groups.*.rules.*.value' => ['nullable'],
            'conditions.*.property' => ['nullable', 'string', 'max:255'],
            'conditions.*.operator' => ['nullable', Rule::in(['equals', 'not_equals', 'contains', 'not_contains', 'in', 'not_in', 'is_empty', 'is_not_empty'])],
            'conditions.*.value' => ['nullable'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    private function normalizeConditions(array $conditions): array
    {
        if (isset($conditions['groups']) && is_array($conditions['groups'])) {
            return [
                'match' => $this->normalizeMatchMode($conditions['match'] ?? 'all'),
                'groups' => collect($conditions['groups'])
                    ->map(function (mixed $group): ?array {
                        if (! is_array($group)) {
                            return null;
                        }

                        $rules = $this->normalizeRules($group['rules'] ?? []);
                        if ($rules === []) {
                            return null;
                        }

                        return [
                            'match' => $this->normalizeMatchMode($group['match'] ?? 'all'),
                            'rules' => $rules,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all(),
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

        $rules = collect($conditions)
            ->map(fn (mixed $value, string|int $property): array => [
                'property' => trim((string) $property),
                'operator' => 'equals',
                'value' => trim((string) ($value ?? '')),
            ])
            ->filter(fn (array $rule): bool => $rule['property'] !== '')
            ->values()
            ->all();

        return [
            'match' => 'all',
            'groups' => $rules === [] ? [] : [[
                'match' => 'all',
                'rules' => $rules,
            ]],
        ];
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
                    'operator' => $this->normalizeOperator($rule['operator'] ?? 'equals'),
                    'value' => in_array($rule['operator'] ?? 'equals', ['is_empty', 'is_not_empty'], true)
                        ? null
                        : trim((string) ($rule['value'] ?? '')),
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

    private function normalizeOperator(mixed $operator): string
    {
        $allowed = ['equals', 'not_equals', 'contains', 'not_contains', 'in', 'not_in', 'is_empty', 'is_not_empty'];
        $normalized = trim((string) $operator);

        return in_array($normalized, $allowed, true) ? $normalized : 'equals';
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AutomationFlow;
use App\Models\Client;
use App\Services\Lite\MessageRuleResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AutomationFlowManagementController extends Controller
{
    public function store(Request $request, Client $client, MessageRuleResolver $conditionResolver): RedirectResponse
    {
        $data = $this->validatePayload($request, $client);
        $flow = DB::transaction(function () use ($client, $conditionResolver, $data): AutomationFlow {
            $flow = $client->automationFlows()->create($this->attributes($data, $conditionResolver));
            $flow->owners()->sync($data['owner_ids'] ?? []);

            return $flow;
        });

        return redirect()->route('admin.clients.flows.edit', [$client, $flow])
            ->with('success', 'Flujo creado. Ahora puedes configurar sus reglas Treble.');
    }

    public function update(
        Request $request,
        Client $client,
        AutomationFlow $flow,
        MessageRuleResolver $conditionResolver
    ): RedirectResponse {
        abort_unless($flow->client_id === $client->id, 404);
        $data = $this->validatePayload($request, $client);
        DB::transaction(function () use ($conditionResolver, $data, $flow): void {
            $flow->update($this->attributes($data, $conditionResolver));
            $flow->owners()->sync($data['owner_ids'] ?? []);
            $flow->messageRules()->update([
                'trigger_property' => $flow->trigger_property,
                'trigger_value' => $flow->trigger_value,
            ]);
        });

        return redirect()->route('admin.clients.flows.edit', [$client, $flow])
            ->with('success', 'Flujo actualizado correctamente.');
    }

    public function destroy(Client $client, AutomationFlow $flow): RedirectResponse
    {
        abort_unless($flow->client_id === $client->id, 404);
        $flow->delete();

        return redirect()->route('admin.clients.flows', $client)
            ->with('success', 'Flujo eliminado correctamente.');
    }

    private function validatePayload(Request $request, Client $client): array
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'integer'],
            'trigger_property' => ['required', 'string', 'max:255'],
            'trigger_value' => ['nullable', 'string', 'max:255'],
            'conditions' => ['sometimes', 'array'],
            'conditions.match' => ['nullable', Rule::in(['all', 'any'])],
            'conditions.groups' => ['sometimes', 'array'],
            'conditions.groups.*.match' => ['nullable', Rule::in(['all', 'any'])],
            'conditions.groups.*.rules' => ['sometimes', 'array'],
            'conditions.groups.*.rules.*.property' => ['nullable', 'string', 'max:255'],
            'conditions.groups.*.rules.*.operator' => ['nullable', Rule::in(['equals', 'not_equals', 'contains', 'not_contains', 'in', 'not_in', 'is_empty', 'is_not_empty'])],
            'conditions.groups.*.rules.*.value' => ['nullable'],
            'owner_assignment_enabled' => ['required', 'boolean'],
            'owner_property' => ['exclude_if:owner_assignment_enabled,false', 'required', 'string', 'max:255'],
            'owner_selection_strategy' => ['exclude_if:owner_assignment_enabled,false', 'required', Rule::in(['random', 'sequential'])],
            'existing_owner_behavior' => ['exclude_if:owner_assignment_enabled,false', 'required', Rule::in(['stop', 'continue'])],
            'owner_ids' => ['exclude_if:owner_assignment_enabled,false', 'required', 'array', 'min:1'],
            'owner_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('hubspot_owners', 'id')->where(fn ($query) => $query
                    ->where('client_id', $client->id)
                    ->where('active', true)),
            ],
            'continue_to_treble' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            $ownerAssignmentEnabled = $request->boolean('owner_assignment_enabled');
            $continueToTreble = $request->boolean('continue_to_treble');

            if (! $ownerAssignmentEnabled && ! $continueToTreble) {
                $validator->errors()->add(
                    'continue_to_treble',
                    'El flujo debe habilitar la asignación de propietario o el paso Treble.'
                );
            }

            if (! $ownerAssignmentEnabled) {
                return;
            }

            $ownerProperty = trim((string) $request->input('owner_property', 'hubspot_owner_id'));
            $groups = $request->input('conditions.groups', []);

            if ($ownerProperty === '' || ! is_array($groups)) {
                return;
            }

            foreach ($groups as $groupIndex => $group) {
                $rules = is_array($group) ? ($group['rules'] ?? []) : [];
                if (! is_array($rules)) {
                    continue;
                }

                foreach ($rules as $ruleIndex => $condition) {
                    if (! is_array($condition)) {
                        continue;
                    }

                    if (trim((string) ($condition['property'] ?? '')) !== $ownerProperty) {
                        continue;
                    }

                    $validator->errors()->add(
                        "conditions.groups.{$groupIndex}.rules.{$ruleIndex}.property",
                        'La propiedad del propietario se controla con la política del paso 1 y no debe repetirse como condición.'
                    );
                }
            }
        });

        return $validator->validate();
    }

    private function attributes(array $data, MessageRuleResolver $conditionResolver): array
    {
        return [
            'name' => $data['name'],
            'priority' => (int) ($data['priority'] ?? 100),
            'trigger_property' => $data['trigger_property'],
            'trigger_value' => $data['trigger_value'] ?? null,
            'conditions' => $conditionResolver->normalizeConditions($data['conditions'] ?? []),
            'owner_assignment_enabled' => (bool) $data['owner_assignment_enabled'],
            'owner_property' => ($data['owner_property'] ?? null) ?: 'hubspot_owner_id',
            'owner_selection_strategy' => ($data['owner_selection_strategy'] ?? null) ?: 'random',
            'existing_owner_behavior' => ($data['existing_owner_behavior'] ?? null) ?: 'stop',
            'continue_to_treble' => (bool) $data['continue_to_treble'],
            'active' => (bool) $data['active'],
        ];
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MessageRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MessageRuleManagementController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $this->validatePayload($request, $client);

        MessageRule::query()->create([
            'client_id' => $client->id,
            'treble_template_id' => $data['treble_template_id'],
            'name' => $data['name'],
            'priority' => (int) ($data['priority'] ?? 100),
            'trigger_property' => $data['trigger_property'],
            'trigger_value' => $data['trigger_value'] ?? null,
            'conditions' => $this->normalizeConditions($data['conditions'] ?? []),
            'active' => (bool) ($data['active'] ?? true),
        ]);

        return redirect()->route('admin.clients.rules', $client)->with('success', 'Regla creada correctamente.');
    }

    public function update(Request $request, Client $client, MessageRule $rule): RedirectResponse
    {
        abort_unless($rule->client_id === $client->id, 404);
        $data = $this->validatePayload($request, $client);

        $rule->update([
            'treble_template_id' => $data['treble_template_id'],
            'name' => $data['name'],
            'priority' => (int) ($data['priority'] ?? 100),
            'trigger_property' => $data['trigger_property'],
            'trigger_value' => $data['trigger_value'] ?? null,
            'conditions' => $this->normalizeConditions($data['conditions'] ?? []),
            'active' => (bool) ($data['active'] ?? false),
        ]);

        return redirect()->route('admin.clients.rules', $client)->with('success', 'Regla actualizada correctamente.');
    }

    public function destroy(Client $client, MessageRule $rule): RedirectResponse
    {
        abort_unless($rule->client_id === $client->id, 404);
        $rule->delete();

        return redirect()->route('admin.clients.rules', $client)->with('success', 'Regla eliminada correctamente.');
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
            'trigger_property' => ['required', 'string', 'max:255'],
            'trigger_value' => ['nullable', 'string', 'max:255'],
            'conditions' => ['sometimes', 'array'],
            'conditions.*.property' => ['nullable', 'string', 'max:255'],
            'conditions.*.value' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    private function normalizeConditions(array $conditions): array
    {
        if (array_is_list($conditions)) {
            $normalized = [];

            foreach ($conditions as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $property = trim((string) ($item['property'] ?? ''));
                if ($property === '') {
                    continue;
                }

                $normalized[$property] = trim((string) ($item['value'] ?? ''));
            }

            return $normalized;
        }

        return collect($conditions)
            ->mapWithKeys(function (mixed $value, string|int $property): array {
                $key = trim((string) $property);

                return $key === ''
                    ? []
                    : [$key => trim((string) ($value ?? ''))];
            })
            ->all();
    }
}

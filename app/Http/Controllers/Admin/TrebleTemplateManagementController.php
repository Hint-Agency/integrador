<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\TrebleTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TrebleTemplateManagementController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $this->validatePayload($request);

        TrebleTemplate::query()->create([
            'client_id' => $client->id,
            'name' => $data['name'],
            'external_template_id' => $data['external_template_id'],
            'payload_mapping' => $data['payload_mapping'] ?? [],
            'request_template' => $data['request_template'] ?? [],
            'active' => (bool) ($data['active'] ?? true),
        ]);

        return redirect()->route('admin.clients.templates', $client)->with('success', 'Plantilla creada correctamente.');
    }

    public function update(Request $request, Client $client, TrebleTemplate $template): RedirectResponse
    {
        abort_unless($template->client_id === $client->id, 404);
        $data = $this->validatePayload($request);

        $template->update([
            'name' => $data['name'],
            'external_template_id' => $data['external_template_id'],
            'payload_mapping' => $data['payload_mapping'] ?? [],
            'request_template' => $data['request_template'] ?? [],
            'active' => (bool) ($data['active'] ?? false),
        ]);

        return redirect()->route('admin.clients.templates', $client)->with('success', 'Plantilla actualizada correctamente.');
    }

    public function destroy(Client $client, TrebleTemplate $template): RedirectResponse
    {
        abort_unless($template->client_id === $client->id, 404);
        $template->delete();

        return redirect()->route('admin.clients.templates', $client)->with('success', 'Plantilla eliminada correctamente.');
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'external_template_id' => ['required', 'string', 'max:255'],
            'payload_mapping' => ['sometimes', 'array'],
            'request_template' => ['sometimes', 'array'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}

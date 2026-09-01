<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\HubspotOwner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HubspotOwnerManagementController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $this->validatePayload($request, $client);

        $client->hubspotOwners()->create($data);

        return redirect()->route('admin.clients.owners', $client)
            ->with('success', 'Propietario creado correctamente.');
    }

    public function update(Request $request, Client $client, HubspotOwner $owner): RedirectResponse
    {
        abort_unless($owner->client_id === $client->id, 404);
        $owner->update($this->validatePayload($request, $client, $owner));

        return redirect()->route('admin.clients.owners', $client)
            ->with('success', 'Propietario actualizado correctamente.');
    }

    public function destroy(Client $client, HubspotOwner $owner): RedirectResponse
    {
        abort_unless($owner->client_id === $client->id, 404);
        $owner->delete();

        return redirect()->route('admin.clients.owners', $client)
            ->with('success', 'Propietario eliminado correctamente.');
    }

    private function validatePayload(Request $request, Client $client, ?HubspotOwner $owner = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'external_owner_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique('hubspot_owners')->where('client_id', $client->id)->ignore($owner?->id),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'active' => ['required', 'boolean'],
        ]);
    }
}

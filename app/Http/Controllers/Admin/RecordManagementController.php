<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Record;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecordManagementController extends Controller
{
    public function cleanup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['all', 'filtered', 'older_than'])],
            'status' => ['nullable', Rule::in(['init', 'processing', 'success', 'warning', 'error'])],
            'event_type' => ['nullable', 'string', 'max:255'],
            'older_than_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'keep_warnings_errors' => ['nullable', 'boolean'],
        ]);

        $query = Record::query();

        if (($data['mode'] ?? null) === 'filtered') {
            if (empty($data['status']) && empty($data['event_type'])) {
                return back()->with('error', 'Selecciona al menos un filtro para limpiar registros.');
            }

            if (! empty($data['status'])) {
                $query->where('status', $data['status']);
            }

            if (! empty($data['event_type'])) {
                $query->where('event_type', $data['event_type']);
            }
        }

        if (($data['mode'] ?? null) === 'older_than') {
            $days = (int) ($data['older_than_days'] ?? 30);
            $query->where('created_at', '<', now()->subDays($days));
        }

        if ((bool) ($data['keep_warnings_errors'] ?? false)) {
            $query->whereNotIn('status', ['warning', 'error']);
        }

        $deleted = $query->delete();

        return back()->with('success', sprintf('Limpieza de registros completada. Registros eliminados: %d.', $deleted));
    }
}

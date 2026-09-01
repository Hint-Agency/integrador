<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PlatformConnection;
use App\Services\Hubspot\HubspotCredentialValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformConnectionManagementController extends Controller
{
    public function __construct(
        private readonly HubspotCredentialValidationService $hubspotCredentialValidationService
    ) {}

    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $this->validatePayload($request, $client);

        PlatformConnection::query()->create($this->buildAttributes($client, $data));

        return redirect()->route('admin.clients.connections', $client)->with('success', 'Conexión creada correctamente.');
    }

    public function update(Request $request, Client $client, PlatformConnection $connection): RedirectResponse
    {
        abort_unless($connection->client_id === $client->id, 404);
        $data = $this->validatePayload($request, $client, $connection);
        $connection->update($this->buildAttributes($client, $data, $connection));

        return redirect()->route('admin.clients.connections', $client)->with('success', 'Conexión actualizada correctamente.');
    }

    public function rotateWebhookSecret(Client $client, PlatformConnection $connection): RedirectResponse
    {
        abort_unless($connection->client_id === $client->id, 404);
        abort_unless($connection->platform_type === 'treble', 422);

        $connection->update([
            'webhook_secret' => PlatformConnection::generateWebhookSecret(),
            'signature_header' => $connection->signature_header ?: 'X-Treble-Webhook-Secret',
        ]);

        return redirect()
            ->route('admin.clients.connections.edit', [$client, $connection])
            ->with('success', 'Webhook secret de Treble regenerado correctamente.');
    }

    public function revokeWebhookSecret(Client $client, PlatformConnection $connection): RedirectResponse
    {
        abort_unless($connection->client_id === $client->id, 404);
        abort_unless($connection->platform_type === 'treble', 422);

        $connection->update([
            'webhook_secret' => null,
        ]);

        return redirect()
            ->route('admin.clients.connections.edit', [$client, $connection])
            ->with('success', 'Webhook secret de Treble revocado correctamente.');
    }

    public function destroy(Client $client, PlatformConnection $connection): RedirectResponse
    {
        abort_unless($connection->client_id === $client->id, 404);
        $connection->delete();

        return redirect()->route('admin.clients.connections', $client)->with('success', 'Conexión eliminada correctamente.');
    }

    private function validatePayload(Request $request, Client $client, ?PlatformConnection $connection = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('platform_connections', 'slug')
                    ->where(fn ($query) => $query->where('client_id', $client->id))
                    ->ignore($connection?->id),
            ],
            'platform_type' => ['required', Rule::in(['hubspot', 'treble'])],
            'base_url' => ['nullable', 'url'],
            'signature_header' => ['nullable', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
            'credentials' => ['sometimes', 'array'],
            'settings' => ['sometimes', 'array'],
        ]);

        $credentials = is_array($data['credentials'] ?? null) ? $data['credentials'] : [];
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $errors = [];

        if ($data['platform_type'] === 'hubspot') {
            $newToken = trim((string) ($credentials['access_token'] ?? ''));
            $existingToken = trim((string) ($connection?->credentials['access_token'] ?? ''));
            $accessToken = $newToken !== '' ? $newToken : $existingToken;
            $appId = $settings['app_id'] ?? $connection?->settings['app_id'] ?? null;
            $normalizedAppId = filter_var($appId, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);

            if ($accessToken === '') {
                $errors['credentials.access_token'] = 'HubSpot requiere access token.';
            }

            if (! filled($appId)) {
                $errors['settings.app_id'] = 'HubSpot requiere el App ID.';
            } elseif ($normalizedAppId === false) {
                $errors['settings.app_id'] = 'El App ID debe ser un número entero mayor a cero.';
            }

            if ($errors === []) {
                $data['settings'] = array_merge($settings, ['app_id' => $normalizedAppId]);
                $settings = $data['settings'];
            }

            if ($errors === [] && $this->shouldValidateHubspotCredentials($connection, $newToken, $normalizedAppId)) {
                $validation = $this->hubspotCredentialValidationService->validateAppId(
                    (string) ($data['base_url'] ?? $connection?->base_url ?: 'https://api.hubapi.com'),
                    $accessToken,
                    $normalizedAppId,
                    (int) ($settings['timeout_seconds'] ?? $connection?->settings['timeout_seconds'] ?? 20)
                );

                if (! ($validation['success'] ?? false)) {
                    $errorField = ($validation['reason'] ?? null) === 'app_id_mismatch'
                        ? 'settings.app_id'
                        : 'credentials.access_token';
                    $errors[$errorField] = (string) ($validation['message'] ?? 'No fue posible validar las credenciales de HubSpot.');
                } else {
                    $data['settings'] = array_merge($settings, [
                        'app_id' => $normalizedAppId,
                        'credential_validation' => [
                            'app_id' => $validation['app_id'],
                            'hub_id' => $validation['hub_id'],
                            'expires_in' => $validation['expires_in'],
                            'credential_type' => $validation['credential_type'],
                            'validated_at' => now()->toISOString(),
                        ],
                    ]);
                    $settings = $data['settings'];
                }
            }
        }

        if ($data['platform_type'] === 'treble') {
            if (! filled($data['base_url'] ?? null) && ! filled($connection?->base_url ?? null)) {
                $errors['base_url'] = 'Treble requiere base URL.';
            }

            if (! filled($settings['send_path'] ?? null) && ! filled($connection?->settings['send_path'] ?? null)) {
                $errors['settings.send_path'] = 'Treble requiere send path.';
            }

            $authMode = (string) ($settings['auth_mode'] ?? $connection?->settings['auth_mode'] ?? '');
            $isActive = (bool) ($data['active'] ?? $connection?->active ?? true);
            if (($authMode === 'bearer_api_key' || $authMode === 'authorization_header' || $authMode === 'header_api_key') && $isActive) {
                $hasApiKey = filled($credentials['api_key'] ?? null)
                    || filled($connection?->credentials['api_key'] ?? null);

                if (! $hasApiKey) {
                    $errors['credentials.api_key'] = 'Treble requiere API key para activarse con el modo de autenticación seleccionado.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    private function shouldValidateHubspotCredentials(
        ?PlatformConnection $connection,
        string $newToken,
        int $appId
    ): bool {
        if ($connection === null || $newToken !== '') {
            return true;
        }

        $currentAppId = (int) ($connection->settings['app_id'] ?? 0);
        $validatedAppId = (int) ($connection->settings['credential_validation']['app_id'] ?? 0);

        return $currentAppId !== $appId || $validatedAppId !== $appId;
    }

    private function buildAttributes(Client $client, array $data, ?PlatformConnection $connection = null): array
    {
        $credentials = $connection?->credentials ?? [];
        $hasNewApiKey = false;
        foreach (($data['credentials'] ?? []) as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                continue;
            }

            $credentials[$key] = $value;
            if ($key === 'api_key') {
                $hasNewApiKey = true;
            }
        }

        $settings = array_merge($connection?->settings ?? [], Arr::wrap($data['settings'] ?? []));
        $platformType = $data['platform_type'];

        $signatureHeader = $data['signature_header'] ?? null;
        $webhookSecret = filled($data['webhook_secret'] ?? null)
            ? $data['webhook_secret']
            : $connection?->webhook_secret;

        if ($platformType === 'treble') {
            $signatureHeader = filled($signatureHeader) ? $signatureHeader : ($connection?->signature_header ?: 'X-Treble-Webhook-Secret');

            $hasApiKeyAfterSave = filled($credentials['api_key'] ?? null);
            $isCreating = $connection === null;

            if (($hasNewApiKey || ($isCreating && $hasApiKeyAfterSave)) && ! filled($data['webhook_secret'] ?? null)) {
                $webhookSecret = PlatformConnection::generateWebhookSecret();
            }

            $settings = array_merge([
                'status_webhook_enabled' => true,
                'country_code_default' => '52',
            ], $settings);
        }

        return [
            'client_id' => $client->id,
            'platform_type' => $platformType,
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'base_url' => $data['base_url'] ?? null,
            'signature_header' => $signatureHeader,
            'webhook_secret' => $webhookSecret,
            'active' => (bool) ($data['active'] ?? true),
            'credentials' => $credentials,
            'settings' => $settings,
        ];
    }
}

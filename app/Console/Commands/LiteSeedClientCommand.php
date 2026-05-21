<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\PlatformConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class LiteSeedClientCommand extends Command
{
    protected $signature = 'lite:seed-client
        {slug : Client slug}
        {name : Client name}
        {--hubspot-token= : HubSpot access token}
        {--hubspot-secret= : HubSpot webhook secret}
        {--hubspot-base-url=https://api.hubapi.com : HubSpot base URL}
        {--hubspot-signature=x-signature : HubSpot signature header}
        {--treble-base-url= : Treble base URL}
        {--treble-send-path=/messages/send : Treble send path}
        {--treble-auth-mode=authorization_header : Treble auth mode}
        {--treble-api-key= : Treble API key}
        {--inactive-treble : Create Treble connection as inactive}';

    protected $description = 'Create or update a Lite client with HubSpot and optional Treble connections';

    public function handle(): int
    {
        $slug = Str::slug((string) $this->argument('slug'));
        $name = (string) $this->argument('name');

        $client = Client::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'active' => true,
            ]
        );

        $hubspot = PlatformConnection::query()->firstOrNew([
            'client_id' => $client->id,
            'platform_type' => 'hubspot',
            'slug' => 'hubspot',
        ]);

        $hubspot->fill([
            'name' => 'HubSpot',
            'base_url' => (string) $this->option('hubspot-base-url'),
            'signature_header' => (string) $this->option('hubspot-signature'),
            'webhook_secret' => $this->filledOption('hubspot-secret')
                ? (string) $this->option('hubspot-secret')
                : $hubspot->webhook_secret,
            'credentials' => array_filter([
                'access_token' => $this->filledOption('hubspot-token')
                    ? (string) $this->option('hubspot-token')
                    : ($hubspot->credentials['access_token'] ?? null),
            ], static fn (mixed $value): bool => filled($value)),
            'settings' => array_merge($hubspot->settings ?? [], [
                'timeout_seconds' => 30,
                'contact_properties' => [
                    'firstname',
                    'lastname',
                    'phone',
                    'mobilephone',
                    'campus_de_interes',
                    'nivel_escolar_de_interes',
                    'plantilla_de_whatsapp',
                ],
            ]),
            'active' => true,
        ]);
        $hubspot->save();

        $treble = PlatformConnection::query()->firstOrNew([
            'client_id' => $client->id,
            'platform_type' => 'treble',
            'slug' => 'treble',
        ]);

        $trebleCredentials = $treble->credentials ?? [];
        if ($this->filledOption('treble-api-key')) {
            $trebleCredentials['api_key'] = (string) $this->option('treble-api-key');
        }

        $treble->fill([
            'name' => 'Treble',
            'base_url' => $this->filledOption('treble-base-url')
                ? (string) $this->option('treble-base-url')
                : ($treble->base_url ?: null),
            'credentials' => $trebleCredentials,
            'settings' => array_merge($treble->settings ?? [], [
                'send_path' => (string) $this->option('treble-send-path'),
                'http_method' => 'POST',
                'auth_mode' => (string) $this->option('treble-auth-mode'),
                'api_key_header' => 'X-API-Key',
                'timeout_seconds' => 20,
                'headers' => [],
            ]),
            'active' => ! (bool) $this->option('inactive-treble'),
        ]);
        $treble->save();

        $this->info("Client [{$client->slug}] is ready.");
        $this->line("HubSpot connection id: {$hubspot->id}");
        $this->line("Treble connection id: {$treble->id}");

        return self::SUCCESS;
    }

    private function filledOption(string $name): bool
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '';
    }
}

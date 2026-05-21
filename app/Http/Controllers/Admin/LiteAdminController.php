<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MessageRule;
use App\Models\PlatformConnection;
use App\Models\Record;
use App\Models\Role;
use App\Models\TrebleTemplate;
use App\Models\User;

class LiteAdminController extends Controller
{
    public function clients()
    {
        $clients = Client::query()
            ->withCount(['platformConnections', 'trebleTemplates', 'messageRules'])
            ->orderBy('name')
            ->paginate(15)
            ->through(static function (Client $client): array {
                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'slug' => $client->slug,
                    'description' => $client->description,
                    'active' => (bool) $client->active,
                    'platform_connections_count' => $client->platform_connections_count,
                    'treble_templates_count' => $client->treble_templates_count,
                    'message_rules_count' => $client->message_rules_count,
                ];
            });

        return inertia('Admin/Clients', [
            'clients' => $clients,
        ]);
    }

    public function clientsCreate()
    {
        return inertia('Admin/ClientsForm', [
            'mode' => 'create',
            'client' => null,
        ]);
    }

    public function clientsEdit(Client $client)
    {
        return inertia('Admin/ClientsForm', [
            'mode' => 'edit',
            'client' => $client->only(['id', 'name', 'slug', 'description', 'active']),
        ]);
    }

    public function clientConnections(Client $client)
    {
        $connections = $client->platformConnections()
            ->orderBy('platform_type')
            ->orderBy('name')
            ->get()
            ->map(function (PlatformConnection $connection) use ($client): array {
                return [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'slug' => $connection->slug,
                    'platform_type' => $connection->platform_type,
                    'base_url' => $connection->base_url,
                    'signature_header' => $connection->signature_header,
                    'active' => (bool) $connection->active,
                    'settings' => $connection->settings ?? [],
                    'has_credentials' => ! empty($connection->credentials ?? []),
                    'has_webhook_secret' => filled($connection->webhook_secret),
                    'status_webhook_url' => $connection->platform_type === 'treble'
                        ? url('/webhooks/' . $client->slug . '/treble/status')
                        : null,
                ];
            });

        return inertia('Admin/PlatformConnections', [
            'client' => $client->only(['id', 'name', 'slug']),
            'connections' => $connections,
        ]);
    }

    public function clientConnectionsCreate(Client $client)
    {
        return inertia('Admin/PlatformConnectionsForm', [
            'mode' => 'create',
            'client' => $client->only(['id', 'name', 'slug']),
            'connection' => null,
        ]);
    }

    public function clientConnectionsEdit(Client $client, PlatformConnection $connection)
    {
        abort_unless($connection->client_id === $client->id, 404);

        return inertia('Admin/PlatformConnectionsForm', [
            'mode' => 'edit',
            'client' => $client->only(['id', 'name', 'slug']),
            'connection' => [
                'id' => $connection->id,
                'name' => $connection->name,
                'slug' => $connection->slug,
                'platform_type' => $connection->platform_type,
                'base_url' => $connection->base_url,
                'signature_header' => $connection->signature_header,
                'active' => (bool) $connection->active,
                'settings' => $connection->settings ?? [],
                'has_credentials' => ! empty($connection->credentials ?? []),
                'has_webhook_secret' => filled($connection->webhook_secret),
                'revealed_webhook_secret' => $connection->platform_type === 'treble'
                    ? $connection->getAttribute('webhook_secret')
                    : null,
                'status_webhook_url' => $connection->platform_type === 'treble'
                    ? url('/webhooks/' . $client->slug . '/treble/status')
                    : null,
            ],
        ]);
    }

    public function clientTemplates(Client $client)
    {
        $templates = $client->trebleTemplates()
            ->orderBy('name')
            ->get()
            ->map(static function (TrebleTemplate $template): array {
                return [
                    'id' => $template->id,
                    'name' => $template->name,
                    'external_template_id' => $template->external_template_id,
                    'payload_mapping' => $template->payload_mapping ?? [],
                    'request_template' => $template->request_template ?? [],
                    'active' => (bool) $template->active,
                ];
            });

        return inertia('Admin/TrebleTemplates', [
            'client' => $client->only(['id', 'name', 'slug']),
            'templates' => $templates,
        ]);
    }

    public function clientTemplatesCreate(Client $client)
    {
        return inertia('Admin/TrebleTemplatesForm', [
            'mode' => 'create',
            'client' => $client->only(['id', 'name', 'slug']),
            'template' => null,
        ]);
    }

    public function clientTemplatesEdit(Client $client, TrebleTemplate $template)
    {
        abort_unless($template->client_id === $client->id, 404);

        return inertia('Admin/TrebleTemplatesForm', [
            'mode' => 'edit',
            'client' => $client->only(['id', 'name', 'slug']),
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'external_template_id' => $template->external_template_id,
                'payload_mapping' => $template->payload_mapping ?? [],
                'request_template' => $template->request_template ?? [],
                'active' => (bool) $template->active,
            ],
        ]);
    }

    public function clientRules(Client $client)
    {
        $rules = $client->messageRules()
            ->with('trebleTemplate:id,name,external_template_id')
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get()
            ->map(static function (MessageRule $rule): array {
                return [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'priority' => $rule->priority,
                    'trigger_property' => $rule->trigger_property,
                    'trigger_value' => $rule->trigger_value,
                    'conditions' => $rule->conditions ?? [],
                    'conditions_list' => collect($rule->conditions ?? [])
                        ->map(fn (mixed $value, string|int $property) => [
                            'property' => (string) $property,
                            'value' => is_scalar($value) ? (string) $value : '',
                        ])->values()->all(),
                    'active' => (bool) $rule->active,
                    'treble_template_id' => $rule->treble_template_id,
                    'treble_template' => $rule->trebleTemplate ? [
                        'id' => $rule->trebleTemplate->id,
                        'name' => $rule->trebleTemplate->name,
                        'external_template_id' => $rule->trebleTemplate->external_template_id,
                    ] : null,
                ];
            });

        return inertia('Admin/MessageRules', [
            'client' => $client->only(['id', 'name', 'slug']),
            'rules' => $rules,
            'templates' => $client->trebleTemplates()->where('active', true)->orderBy('name')->get(['id', 'name', 'external_template_id']),
        ]);
    }

    public function clientRulesCreate(Client $client)
    {
        return inertia('Admin/MessageRulesForm', [
            'mode' => 'create',
            'client' => $client->only(['id', 'name', 'slug']),
            'rule' => null,
            'templates' => $client->trebleTemplates()->where('active', true)->orderBy('name')->get(['id', 'name', 'external_template_id']),
        ]);
    }

    public function clientRulesEdit(Client $client, MessageRule $rule)
    {
        abort_unless($rule->client_id === $client->id, 404);

        return inertia('Admin/MessageRulesForm', [
            'mode' => 'edit',
            'client' => $client->only(['id', 'name', 'slug']),
            'rule' => [
                'id' => $rule->id,
                'name' => $rule->name,
                'priority' => $rule->priority,
                'trigger_property' => $rule->trigger_property,
                'trigger_value' => $rule->trigger_value,
                'conditions' => $rule->conditions ?? [],
                'conditions_list' => collect($rule->conditions ?? [])
                    ->map(fn (mixed $value, string|int $property) => [
                        'property' => (string) $property,
                        'value' => is_scalar($value) ? (string) $value : '',
                    ])->values()->all(),
                'active' => (bool) $rule->active,
                'treble_template_id' => $rule->treble_template_id,
            ],
            'templates' => $client->trebleTemplates()->where('active', true)->orderBy('name')->get(['id', 'name', 'external_template_id']),
        ]);
    }

    public function users()
    {
        $users = User::query()
            ->with('roles:id,name,slug')
            ->whereDoesntHave('roles', static function ($query): void {
                $query->where('slug', 'superadmin');
            })
            ->orderBy('name')
            ->paginate(20)
            ->through(static function (User $user): array {
                return [
                    'id' => $user->id,
                    'username' => $user->username,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->map(fn ($role) => [
                        'id' => $role->id,
                        'name' => $role->name,
                        'slug' => $role->slug,
                    ])->values(),
                ];
            });

        return inertia('Admin/Users', [
            'users' => $users,
            'roles' => Role::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug']),
        ]);
    }

    public function usersCreate()
    {
        return inertia('Admin/UsersForm', [
            'mode' => 'create',
            'user' => null,
            'roles' => Role::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function usersEdit(User $user)
    {
        return inertia('Admin/UsersForm', [
            'mode' => 'edit',
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => $user->name,
                'email' => $user->email,
                'role_ids' => $user->roles()->pluck('roles.id')->all(),
            ],
            'roles' => Role::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function roles()
    {
        $roles = Role::query()
            ->with('permissions:id,name,slug')
            ->orderBy('name')
            ->paginate(20)
            ->through(static function (Role $role): array {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'description' => $role->description,
                    'permissions' => $role->permissions->map(fn ($permission) => [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'slug' => $permission->slug,
                    ])->values(),
                ];
            });

        return inertia('Admin/Roles', [
            'roles' => $roles,
        ]);
    }

    public function rolesCreate()
    {
        return inertia('Admin/RolesForm', [
            'mode' => 'create',
            'role' => null,
        ]);
    }

    public function rolesEdit(Role $role)
    {
        return inertia('Admin/RolesForm', [
            'mode' => 'edit',
            'role' => $role->only(['id', 'name', 'slug', 'description']),
        ]);
    }

    public function globalRecords()
    {
        $records = Record::query()
            ->with('client:id,name,slug')
            ->latest('id')
            ->paginate(20)
            ->through(static function (Record $record): array {
                return [
                    'id' => $record->id,
                    'event_type' => $record->event_type,
                    'status' => $record->status,
                    'message' => $record->message,
                    'client' => $record->client ? $record->client->only(['id', 'name', 'slug']) : null,
                    'created_at' => optional($record->created_at)?->toDateTimeString(),
                ];
            });

        return inertia('Admin/Records', [
            'records' => $records,
        ]);
    }
}

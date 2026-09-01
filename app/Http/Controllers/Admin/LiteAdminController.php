<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AutomationFlow;
use App\Models\Client;
use App\Models\HubspotOwner;
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
                        ? url('/webhooks/'.$client->slug.'/treble/status')
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
                    ? url('/webhooks/'.$client->slug.'/treble/status')
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

    public function clientFlowRulesCreate(Client $client, AutomationFlow $flow)
    {
        abort_unless($flow->client_id === $client->id, 404);

        return inertia('Admin/MessageRulesForm', [
            'mode' => 'create',
            'client' => $client->only(['id', 'name', 'slug']),
            'flow' => $flow->only(['id', 'name', 'trigger_property', 'trigger_value']),
            'rule' => null,
            'templates' => $this->templateOptions($client),
        ]);
    }

    public function clientFlowRulesEdit(Client $client, AutomationFlow $flow, MessageRule $rule)
    {
        abort_unless($flow->client_id === $client->id && $rule->automation_flow_id === $flow->id, 404);

        return inertia('Admin/MessageRulesForm', [
            'mode' => 'edit',
            'client' => $client->only(['id', 'name', 'slug']),
            'flow' => $flow->only(['id', 'name', 'trigger_property', 'trigger_value']),
            'rule' => [
                'id' => $rule->id,
                'name' => $rule->name,
                'priority' => $rule->priority,
                'conditions' => $rule->conditions ?? [],
                'condition_builder' => $this->buildConditionBuilder($rule->conditions ?? []),
                'active' => (bool) $rule->active,
                'treble_template_id' => $rule->treble_template_id,
            ],
            'templates' => $this->templateOptions($client),
        ]);
    }

    public function clientOwners(Client $client)
    {
        return inertia('Admin/HubspotOwners', [
            'client' => $client->only(['id', 'name', 'slug']),
            'owners' => $client->hubspotOwners()
                ->withCount('automationFlows')
                ->orderBy('name')
                ->get()
                ->map(fn (HubspotOwner $owner): array => [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'external_owner_id' => $owner->external_owner_id,
                    'email' => $owner->email,
                    'active' => (bool) $owner->active,
                    'automation_flows_count' => $owner->automation_flows_count,
                ]),
        ]);
    }

    public function clientOwnersCreate(Client $client)
    {
        return inertia('Admin/HubspotOwnersForm', [
            'mode' => 'create',
            'client' => $client->only(['id', 'name', 'slug']),
            'owner' => null,
        ]);
    }

    public function clientOwnersEdit(Client $client, HubspotOwner $owner)
    {
        abort_unless($owner->client_id === $client->id, 404);

        return inertia('Admin/HubspotOwnersForm', [
            'mode' => 'edit',
            'client' => $client->only(['id', 'name', 'slug']),
            'owner' => $owner->only(['id', 'name', 'external_owner_id', 'email', 'active']),
        ]);
    }

    public function clientFlows(Client $client)
    {
        return inertia('Admin/AutomationFlows', [
            'client' => $client->only(['id', 'name', 'slug']),
            'flows' => $client->automationFlows()
                ->with([
                    'owners:id,name,external_owner_id',
                    'messageRules' => fn ($query) => $query
                        ->with('trebleTemplate:id,name,external_template_id')
                        ->orderByDesc('priority'),
                ])
                ->orderByDesc('priority')
                ->orderBy('name')
                ->get()
                ->map(function (AutomationFlow $flow): array {
                    $conditionBuilder = $this->buildConditionBuilder($flow->conditions ?? []);

                    return [
                        'id' => $flow->id,
                        'name' => $flow->name,
                        'priority' => $flow->priority,
                        'trigger_property' => $flow->trigger_property,
                        'trigger_value' => $flow->trigger_value,
                        'group_count' => count($conditionBuilder['groups']),
                        'condition_count' => collect($conditionBuilder['groups'])
                            ->sum(fn (array $group): int => count($group['rules'] ?? [])),
                        'owner_assignment_enabled' => (bool) $flow->owner_assignment_enabled,
                        'owner_property' => $flow->owner_property,
                        'existing_owner_behavior' => $flow->existing_owner_behavior ?: 'stop',
                        'owners' => $flow->owners->map->only(['id', 'name', 'external_owner_id'])->values(),
                        'continue_to_treble' => (bool) $flow->continue_to_treble,
                        'message_rules_count' => $flow->messageRules->count(),
                        'message_rules' => $flow->messageRules->map(fn (MessageRule $rule): array => [
                            'id' => $rule->id,
                            'name' => $rule->name,
                            'priority' => $rule->priority,
                            'active' => (bool) $rule->active,
                            'treble_template' => $rule->trebleTemplate?->only(['id', 'name', 'external_template_id']),
                        ])->values(),
                        'active' => (bool) $flow->active,
                    ];
                }),
        ]);
    }

    public function clientFlowsCreate(Client $client)
    {
        return inertia('Admin/AutomationFlowsForm', [
            'mode' => 'create',
            'client' => $client->only(['id', 'name', 'slug']),
            'flow' => null,
            'owners' => $this->ownerOptions($client),
            'messageRules' => [],
        ]);
    }

    public function clientFlowsEdit(Client $client, AutomationFlow $flow)
    {
        abort_unless($flow->client_id === $client->id, 404);
        $flow->load(['messageRules.trebleTemplate']);

        return inertia('Admin/AutomationFlowsForm', [
            'mode' => 'edit',
            'client' => $client->only(['id', 'name', 'slug']),
            'flow' => [
                'id' => $flow->id,
                'name' => $flow->name,
                'priority' => $flow->priority,
                'trigger_property' => $flow->trigger_property,
                'trigger_value' => $flow->trigger_value,
                'condition_builder' => $this->buildConditionBuilder($flow->conditions ?? []),
                'owner_assignment_enabled' => (bool) $flow->owner_assignment_enabled,
                'owner_property' => $flow->owner_property,
                'owner_selection_strategy' => $flow->owner_selection_strategy,
                'existing_owner_behavior' => $flow->existing_owner_behavior ?: 'stop',
                'owner_ids' => $flow->owners()->pluck('hubspot_owners.id')->all(),
                'continue_to_treble' => (bool) $flow->continue_to_treble,
                'active' => (bool) $flow->active,
            ],
            'owners' => $this->ownerOptions($client, $flow),
            'messageRules' => $flow->messageRules
                ->sortByDesc('priority')
                ->map(function (MessageRule $rule): array {
                    $conditionBuilder = $this->buildConditionBuilder($rule->conditions ?? []);

                    return [
                        'id' => $rule->id,
                        'name' => $rule->name,
                        'priority' => $rule->priority,
                        'active' => (bool) $rule->active,
                        'condition_count' => collect($conditionBuilder['groups'])
                            ->sum(fn (array $group): int => count($group['rules'] ?? [])),
                        'treble_template' => $rule->trebleTemplate?->only(['id', 'name', 'external_template_id']),
                    ];
                })->values(),
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

    private function buildConditionBuilder(mixed $conditions): array
    {
        if (! is_array($conditions)) {
            return [
                'match' => 'all',
                'groups' => [[
                    'match' => 'all',
                    'rules' => [[
                        'property' => '',
                        'operator' => 'equals',
                        'value' => '',
                    ]],
                ]],
            ];
        }

        if (isset($conditions['groups']) && is_array($conditions['groups'])) {
            $groups = collect($conditions['groups'])
                ->map(function (mixed $group): ?array {
                    if (! is_array($group)) {
                        return null;
                    }

                    $rules = collect($group['rules'] ?? [])
                        ->map(function (mixed $rule): ?array {
                            if (! is_array($rule)) {
                                return null;
                            }

                            return [
                                'property' => (string) ($rule['property'] ?? ''),
                                'operator' => (string) ($rule['operator'] ?? 'equals'),
                                'value' => is_scalar($rule['value'] ?? null) || ($rule['value'] ?? null) === null
                                    ? (string) ($rule['value'] ?? '')
                                    : '',
                            ];
                        })
                        ->filter()
                        ->values()
                        ->all();

                    return [
                        'match' => ($group['match'] ?? 'all') === 'any' ? 'any' : 'all',
                        'rules' => $rules === [] ? [[
                            'property' => '',
                            'operator' => 'equals',
                            'value' => '',
                        ]] : $rules,
                    ];
                })
                ->filter()
                ->values()
                ->all();

            return [
                'match' => ($conditions['match'] ?? 'all') === 'any' ? 'any' : 'all',
                'groups' => $groups === [] ? [[
                    'match' => 'all',
                    'rules' => [[
                        'property' => '',
                        'operator' => 'equals',
                        'value' => '',
                    ]],
                ]] : $groups,
            ];
        }

        if (array_is_list($conditions)) {
            $rules = collect($conditions)
                ->map(function (mixed $rule): ?array {
                    if (! is_array($rule)) {
                        return null;
                    }

                    return [
                        'property' => (string) ($rule['property'] ?? ''),
                        'operator' => (string) ($rule['operator'] ?? 'equals'),
                        'value' => is_scalar($rule['value'] ?? null) || ($rule['value'] ?? null) === null
                            ? (string) ($rule['value'] ?? '')
                            : '',
                    ];
                })
                ->filter()
                ->values()
                ->all();

            return [
                'match' => 'all',
                'groups' => [[
                    'match' => 'all',
                    'rules' => $rules === [] ? [[
                        'property' => '',
                        'operator' => 'equals',
                        'value' => '',
                    ]] : $rules,
                ]],
            ];
        }

        $rules = collect($conditions)
            ->map(fn (mixed $value, string|int $property): array => [
                'property' => (string) $property,
                'operator' => 'equals',
                'value' => is_scalar($value) ? (string) $value : '',
            ])
            ->values()
            ->all();

        return [
            'match' => 'all',
            'groups' => [[
                'match' => 'all',
                'rules' => $rules === [] ? [[
                    'property' => '',
                    'operator' => 'equals',
                    'value' => '',
                ]] : $rules,
            ]],
        ];
    }

    private function ownerOptions(Client $client, ?AutomationFlow $flow = null)
    {
        $selectedIds = $flow?->owners()->pluck('hubspot_owners.id')->all() ?? [];

        return $client->hubspotOwners()
            ->where(function ($query) use ($selectedIds): void {
                $query->where('active', true);
                if ($selectedIds !== []) {
                    $query->orWhereIn('id', $selectedIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'external_owner_id', 'email', 'active']);
    }

    private function templateOptions(Client $client)
    {
        return $client->trebleTemplates()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'external_template_id']);
    }
}

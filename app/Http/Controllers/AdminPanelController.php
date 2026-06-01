<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Models\Category;
use App\Models\Config;
use App\Models\Event;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use App\Models\Record;
use App\Models\Role;
use App\Models\User;
use App\Services\EventMappingContextResolver;
use App\Services\EventProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;

class AdminPanelController extends Controller
{
    public function __construct(
        protected EventProcessingService $eventProcessingService,
        protected EventMappingContextResolver $eventMappingContextResolver
    ) {}

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

    public function events()
    {
        $events = Event::query()
            ->with(['platform:id,name,slug,type', 'to_event:id,name', 'httpConfig'])
            ->orderByDesc('id')
            ->paginate(20)
            ->through(static function (Event $event): array {
                return [
                    'id' => $event->id,
                    'name' => $event->name,
                    'event_type_id' => $event->event_type_id,
                    'event_type_label' => $event->getEventTypeLabel(),
                    'type' => $event->type,
                    'subscription_type' => $event->subscription_type,
                    'method_name' => $event->method_name,
                    'endpoint_api' => $event->endpoint_api,
                    'schedule_expression' => $event->schedule_expression,
                    'command_sql' => $event->command_sql,
                    'enable_update_hubdb' => (bool) $event->enable_update_hubdb,
                    'hubdb_table_id' => $event->hubdb_table_id,
                    'payload_mapping' => $event->payload_mapping ?? [],
                    'meta' => $event->meta ?? [],
                    'active' => (bool) $event->active,
                    'platform' => $event->platform ? [
                        'id' => $event->platform->id,
                        'name' => $event->platform->name,
                        'slug' => $event->platform->slug,
                        'type' => $event->platform->type,
                    ] : null,
                    'to_event' => $event->to_event ? [
                        'id' => $event->to_event->id,
                        'name' => $event->to_event->name,
                    ] : null,
                    'http_config' => $event->httpConfig ? [
                        'id' => $event->httpConfig->id,
                        'method' => $event->httpConfig->method,
                        'base_url' => $event->httpConfig->base_url,
                        'path' => $event->httpConfig->path,
                        'headers_json' => $event->httpConfig->headers_json ?? [],
                        'query_json' => $event->httpConfig->query_json ?? [],
                        'auth_mode' => $event->httpConfig->auth_mode,
                        'auth_config_json' => $event->httpConfig->auth_config_json ?? [],
                        'timeout_seconds' => $event->httpConfig->timeout_seconds,
                        'retry_policy_json' => $event->httpConfig->retry_policy_json ?? [],
                        'idempotency_config_json' => $event->httpConfig->idempotency_config_json ?? [],
                        'allowlist_domains_json' => $event->httpConfig->allowlist_domains_json ?? [],
                        'active' => (bool) $event->httpConfig->active,
                    ] : null,
                ];
            });

        $platforms = Platform::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'type', 'settings', 'credentials']);
        $eventOptions = Event::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return inertia('Admin/Events', [
            'events' => $events,
            'platforms' => $platforms->map(fn (Platform $platform): array => [
                'id' => $platform->id,
                'name' => $platform->name,
                'slug' => $platform->slug,
                'type' => $platform->type,
            ])->values(),
            'event_options' => $eventOptions,
            'event_type_groups' => EventType::groupedOptions(),
            'platform_method_options' => $this->buildPlatformMethodOptions($platforms),
        ]);
    }

    public function eventsCreate()
    {
        $platforms = Platform::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'type', 'settings', 'credentials']);

        return inertia('Admin/EventsForm', [
            'mode' => 'create',
            'event' => null,
            'platforms' => $platforms->map(fn (Platform $platform): array => [
                'id' => $platform->id,
                'name' => $platform->name,
                'slug' => $platform->slug,
                'type' => $platform->type,
            ])->values(),
            'event_options' => Event::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'event_type_groups' => EventType::groupedOptions(),
            'platform_method_options' => $this->buildPlatformMethodOptions($platforms),
        ]);
    }

    public function eventsEdit(Event $event)
    {
        $event->load(['platform:id,name,slug,type', 'to_event:id,name', 'httpConfig']);

        $platforms = Platform::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'type', 'settings', 'credentials']);

        return inertia('Admin/EventsForm', [
            'mode' => 'edit',
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'event_type_id' => $event->event_type_id,
                'event_type_label' => $event->getEventTypeLabel(),
                'type' => $event->type,
                'subscription_type' => $event->subscription_type,
                'method_name' => $event->method_name,
                'endpoint_api' => $event->endpoint_api,
                'schedule_expression' => $event->schedule_expression,
                'command_sql' => $event->command_sql,
                'enable_update_hubdb' => (bool) $event->enable_update_hubdb,
                'hubdb_table_id' => $event->hubdb_table_id,
                'payload_mapping' => $event->payload_mapping ?? [],
                'meta' => $event->meta ?? [],
                'active' => (bool) $event->active,
                'platform' => $event->platform ? [
                    'id' => $event->platform->id,
                    'name' => $event->platform->name,
                    'slug' => $event->platform->slug,
                    'type' => $event->platform->type,
                ] : null,
                'to_event' => $event->to_event ? [
                    'id' => $event->to_event->id,
                    'name' => $event->to_event->name,
                ] : null,
                'http_config' => $event->httpConfig ? [
                    'id' => $event->httpConfig->id,
                    'method' => $event->httpConfig->method,
                    'base_url' => $event->httpConfig->base_url,
                    'path' => $event->httpConfig->path,
                    'headers_json' => $event->httpConfig->headers_json ?? [],
                    'query_json' => $event->httpConfig->query_json ?? [],
                    'auth_mode' => $event->httpConfig->auth_mode,
                    'auth_config_json' => $event->httpConfig->auth_config_json ?? [],
                    'timeout_seconds' => $event->httpConfig->timeout_seconds,
                    'retry_policy_json' => $event->httpConfig->retry_policy_json ?? [],
                    'idempotency_config_json' => $event->httpConfig->idempotency_config_json ?? [],
                    'allowlist_domains_json' => $event->httpConfig->allowlist_domains_json ?? [],
                    'active' => (bool) $event->httpConfig->active,
                ] : null,
            ],
            'platforms' => $platforms->map(fn (Platform $platform): array => [
                'id' => $platform->id,
                'name' => $platform->name,
                'slug' => $platform->slug,
                'type' => $platform->type,
            ])->values(),
            'event_options' => Event::query()
                ->where('id', '!=', $event->id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'event_type_groups' => EventType::groupedOptions(),
            'platform_method_options' => $this->buildPlatformMethodOptions($platforms),
        ]);
    }

    private function buildPlatformMethodOptions($platforms): array
    {
        $options = [];

        foreach ($platforms as $platform) {
            $serviceClass = $this->eventProcessingService->getServiceClass($platform);
            $options[(string) $platform->id] = $this->extractServiceMethodOptions($serviceClass);
        }

        return $options;
    }

    private function extractServiceMethodOptions(?string $serviceClass): array
    {
        if (! is_string($serviceClass) || ! class_exists($serviceClass)) {
            return [];
        }

        $reflection = new ReflectionClass($serviceClass);
        $methodOptions = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(function (ReflectionMethod $method) use ($reflection): bool {
                if ($method->isConstructor() || $method->isDestructor() || $method->isStatic()) {
                    return false;
                }

                if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                    return false;
                }

                return ! in_array($method->getName(), $this->excludedEventMethodNames(), true)
                    && ! Str::startsWith($method->getName(), '__');
            })
            ->map(fn (ReflectionMethod $method): array => [
                'value' => $method->getName(),
                'label' => $method->getName(),
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return $methodOptions;
    }

    /**
     * @return list<string>
     */
    private function excludedEventMethodNames(): array
    {
        return [
            'createRecord',
            'createSuccessResponse',
            'execute',
            'executeEndpointCall',
            'loadEvent',
            'resolveAllowlistDomains',
            'resolveAuthConfig',
            'resolveAuthMode',
            'resolveBody',
            'resolveHeaders',
            'resolveMethod',
            'resolveQueryParams',
            'resolveRetryPolicy',
            'resolveTimeout',
        ];
    }

    public function eventRelationships(Event $event)
    {
        $event->load([
            'platform:id,name,slug,type',
            'to_event:id,name,platform_id',
            'to_event.platform:id,name,slug,type',
            'from_events:id,name,platform_id,to_event_id',
            'from_events.platform:id,name,slug,type',
            'propertyRelationships.property:id,platform_id,name,key,type,required,active',
            'propertyRelationships.relatedProperty:id,platform_id,name,key,type,required,active',
        ]);

        $mappingContext = $this->eventMappingContextResolver->resolve($event);

        $sourceProperties = Property::query()
            ->whereIn('platform_id', $mappingContext['source_platform_ids'] ?: [$event->platform_id])
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'platform_id', 'name', 'key', 'type', 'required']);

        $targetProperties = Property::query()
            ->where('platform_id', $mappingContext['target_platform_id'] ?? $event->platform_id)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'platform_id', 'name', 'key', 'type', 'required']);

        return inertia('Admin/EventPropertyRelationships', [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'event_type_id' => $event->event_type_id,
                'event_type_label' => $event->getEventTypeLabel(),
                'platform' => $event->platform ? [
                    'id' => $event->platform->id,
                    'name' => $event->platform->name,
                    'slug' => $event->platform->slug,
                    'type' => $event->platform->type,
                ] : null,
                'to_event' => $event->to_event ? [
                    'id' => $event->to_event->id,
                    'name' => $event->to_event->name,
                    'platform' => $event->to_event->platform ? [
                        'id' => $event->to_event->platform->id,
                        'name' => $event->to_event->platform->name,
                        'slug' => $event->to_event->platform->slug,
                        'type' => $event->to_event->platform->type,
                    ] : null,
                ] : null,
                'from_events' => $event->from_events->map(static fn (Event $fromEvent): array => [
                    'id' => $fromEvent->id,
                    'name' => $fromEvent->name,
                    'platform' => $fromEvent->platform ? [
                        'id' => $fromEvent->platform->id,
                        'name' => $fromEvent->platform->name,
                        'slug' => $fromEvent->platform->slug,
                        'type' => $fromEvent->platform->type,
                    ] : null,
                ])->values(),
            ],
            'mapping_context' => $mappingContext,
            'source_properties' => $sourceProperties,
            'target_properties' => $targetProperties,
            'relationships' => $event->propertyRelationships->map(static function (PropertyRelationship $relationship): array {
                return [
                    'id' => $relationship->id,
                    'property_id' => $relationship->property_id,
                    'related_property_id' => $relationship->related_property_id,
                    'mapping_key' => $relationship->mapping_key,
                    'active' => (bool) $relationship->active,
                    'meta' => $relationship->meta ?? [],
                    'property' => $relationship->property ? [
                        'id' => $relationship->property->id,
                        'name' => $relationship->property->name,
                        'key' => $relationship->property->key,
                        'type' => $relationship->property->type,
                        'required' => (bool) $relationship->property->required,
                    ] : null,
                    'related_property' => $relationship->relatedProperty ? [
                        'id' => $relationship->relatedProperty->id,
                        'name' => $relationship->relatedProperty->name,
                        'key' => $relationship->relatedProperty->key,
                        'type' => $relationship->relatedProperty->type,
                        'required' => (bool) $relationship->relatedProperty->required,
                    ] : null,
                ];
            })->values(),
        ]);
    }

    public function platforms()
    {
        $platforms = Platform::query()
            ->orderBy('name')
            ->paginate(20)
            ->through(static function (Platform $platform): array {
                return [
                    'id' => $platform->id,
                    'name' => $platform->name,
                    'slug' => $platform->slug,
                    'type' => $platform->type,
                    'signature' => $platform->signature,
                    'has_secret_key' => ! empty($platform->secret_key),
                    'active' => (bool) $platform->active,
                    'credentials' => $platform->credentials ?? [],
                    'settings' => $platform->settings ?? [],
                ];
            });

        return inertia('Admin/Platforms', [
            'platforms' => $platforms,
        ]);
    }

    public function platformsCreate()
    {
        return inertia('Admin/PlatformsForm', [
            'mode' => 'create',
            'platform' => null,
        ]);
    }

    public function platformsEdit(Platform $platform)
    {
        return inertia('Admin/PlatformsForm', [
            'mode' => 'edit',
            'platform' => [
                'id' => $platform->id,
                'name' => $platform->name,
                'slug' => $platform->slug,
                'type' => $platform->type,
                'signature' => $platform->signature,
                'secret_key' => $platform->secret_key,
                'active' => (bool) $platform->active,
                'credentials' => $platform->credentials ?? [],
                'settings' => $platform->settings ?? [],
            ],
        ]);
    }

    public function properties(Request $request)
    {
        $filters = [
            'platform_id' => $request->string('platform_id')->toString(),
            'category_id' => $request->string('category_id')->toString(),
            'type' => $request->string('type')->toString(),
            'search' => $request->string('search')->toString(),
        ];

        $query = Property::query()
            ->with([
                'platform:id,name,slug,type',
                'categories:id,name,slug',
            ]);

        if ($filters['platform_id'] !== '') {
            $query->where('platform_id', (int) $filters['platform_id']);
        }

        if ($filters['type'] !== '') {
            $query->where('type', $filters['type']);
        }

        if ($filters['category_id'] !== '') {
            $query->whereHas('categories', static function ($query) use ($filters): void {
                $query->where('categories.id', (int) $filters['category_id']);
            });
        }

        if ($filters['search'] !== '') {
            $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']).'%';

            $query->where(static function ($query) use ($search): void {
                $query->where('name', 'like', $search)
                    ->orWhere('key', 'like', $search);
            });
        }

        $properties = $query
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(static function (Property $property): array {
                return [
                    'id' => $property->id,
                    'platform_id' => $property->platform_id,
                    'name' => $property->name,
                    'key' => $property->key,
                    'type' => $property->type,
                    'required' => (bool) $property->required,
                    'active' => (bool) $property->active,
                    'meta' => $property->meta ?? [],
                    'categories' => $property->categories->map(static fn (Category $category): array => [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                    ])->values(),
                    'platform' => $property->platform ? [
                        'id' => $property->platform->id,
                        'name' => $property->platform->name,
                        'slug' => $property->platform->slug,
                        'type' => $property->platform->type,
                    ] : null,
                ];
            });

        $platforms = Platform::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'type']);

        $categories = Category::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $defaultTypes = collect(['string', 'integer', 'float', 'boolean', 'datetime', 'file']);
        $propertyTypes = Property::query()
            ->whereNotNull('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->merge($defaultTypes)
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return inertia('Admin/Properties', [
            'properties' => $properties,
            'platforms' => $platforms,
            'categories' => $categories,
            'property_types' => $propertyTypes,
            'filters' => $filters,
        ]);
    }

    public function propertiesCreate()
    {
        return inertia('Admin/PropertiesForm', [
            'mode' => 'create',
            'property' => null,
            'platforms' => Platform::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'type']),
        ]);
    }

    public function propertiesEdit(Property $property)
    {
        return inertia('Admin/PropertiesForm', [
            'mode' => 'edit',
            'property' => [
                'id' => $property->id,
                'platform_id' => $property->platform_id,
                'name' => $property->name,
                'key' => $property->key,
                'type' => $property->type,
                'required' => (bool) $property->required,
                'active' => (bool) $property->active,
                'meta' => $property->meta ?? [],
            ],
            'platforms' => Platform::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'type']),
        ]);
    }

    public function records(Request $request)
    {
        $status = $request->string('status')->toString();
        $eventType = $request->string('event_type')->toString();

        $applyRecordFilters = static function ($query) use ($status, $eventType): void {
            if ($status !== '') {
                $query->where('status', $status);
            }

            if ($eventType !== '') {
                $query->where('event_type', $eventType);
            }
        };

        $childRecordToArray = static function (Record $record): array {
            return [
                'id' => $record->id,
                'event_id' => $record->event_id,
                'record_id' => $record->record_id,
                'event_type' => $record->event_type,
                'status' => $record->status,
                'message' => $record->message,
                'details' => $record->details,
                'payload' => $record->payload,
                'children_count' => $record->childrens_count,
                'created_at' => optional($record->created_at)?->toISOString(),
                'event' => $record->event ? [
                    'id' => $record->event->id,
                    'name' => $record->event->name,
                    'event_type_id' => $record->event->event_type_id,
                ] : null,
            ];
        };

        $query = Record::query()
            ->whereDoesntHave('parent')
            ->with([
                'event:id,name,event_type_id',
                'childrens' => static function ($query): void {
                    $query->with(['event:id,name,event_type_id'])
                        ->withCount('childrens')
                        ->orderBy('id');
                },
            ])
            ->withCount('childrens')
            ->orderByDesc('id');

        if ($status !== '' || $eventType !== '') {
            $query->where(static function ($query) use ($applyRecordFilters): void {
                $query->where(static function ($query) use ($applyRecordFilters): void {
                    $applyRecordFilters($query);
                })->orWhereHas('childrens', static function ($query) use ($applyRecordFilters): void {
                    $applyRecordFilters($query);
                });
            });
        }

        $records = $query->paginate(25)->withQueryString()->through(static function (Record $record) use ($childRecordToArray): array {
            return [
                'id' => $record->id,
                'event_id' => $record->event_id,
                'record_id' => $record->record_id,
                'event_type' => $record->event_type,
                'status' => $record->status,
                'message' => $record->message,
                'details' => $record->details,
                'payload' => $record->payload,
                'children_count' => $record->childrens_count,
                'created_at' => optional($record->created_at)?->toISOString(),
                'event' => $record->event ? [
                    'id' => $record->event->id,
                    'name' => $record->event->name,
                    'event_type_id' => $record->event->event_type_id,
                ] : null,
                'children' => $record->childrens->map($childRecordToArray)->values(),
            ];
        });

        $eventTypes = Record::query()
            ->whereNotNull('event_type')
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type');

        $cleanupStats = [
            'total' => Record::query()->count(),
            'older_than_7_days' => Record::query()->where('created_at', '<', now()->subDays(7))->count(),
            'older_than_30_days' => Record::query()->where('created_at', '<', now()->subDays(30))->count(),
            'older_than_90_days' => Record::query()->where('created_at', '<', now()->subDays(90))->count(),
        ];

        return inertia('Admin/Records', [
            'records' => $records,
            'filters' => [
                'status' => $status,
                'event_type' => $eventType,
            ],
            'event_types' => $eventTypes,
            'status_options' => ['init', 'processing', 'success', 'warning', 'error'],
            'cleanup_stats' => $cleanupStats,
            'can_manage_records' => $request->user()?->hasPermission('records.manage') ?? false,
        ]);
    }

    public function roles()
    {
        $roles = Role::query()
            ->withCount('users')
            ->with('permissions:id,name,slug')
            ->orderBy('name')
            ->paginate(20)
            ->through(static function (Role $role): array {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'description' => $role->description,
                    'users_count' => $role->users_count,
                    'permissions' => $role->permissions->map(fn ($permission) => [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'slug' => $permission->slug,
                    ])->values(),
                ];
            });

        return inertia('Admin/Roles', [
            'roles' => $roles,
            'permissions' => Permission::query()
                ->orderBy('slug')
                ->get(['id', 'name', 'slug']),
        ]);
    }

    public function rolesCreate()
    {
        return inertia('Admin/RolesForm', [
            'mode' => 'create',
            'role' => null,
            'permissions' => Permission::query()
                ->orderBy('slug')
                ->get(['id', 'name', 'slug']),
        ]);
    }

    public function rolesEdit(Role $role)
    {
        $role->load('permissions:id');

        return inertia('Admin/RolesForm', [
            'mode' => 'edit',
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'permission_ids' => $role->permissions->pluck('id')->all(),
            ],
            'permissions' => Permission::query()
                ->orderBy('slug')
                ->get(['id', 'name', 'slug']),
        ]);
    }

    public function categories()
    {
        $categories = Category::query()
            ->withCount('properties')
            ->with('properties:id')
            ->orderBy('name')
            ->paginate(20)
            ->through(static function (Category $category): array {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'active' => (bool) $category->active,
                    'properties_count' => $category->properties_count,
                    'property_ids' => $category->properties->pluck('id')->all(),
                ];
            });

        return inertia('Admin/Categories', [
            'categories' => $categories,
            'properties' => Property::query()
                ->with('platform:id,name,slug,type')
                ->orderBy('name')
                ->get(['id', 'platform_id', 'name', 'key'])
                ->map(static fn (Property $property): array => [
                    'id' => $property->id,
                    'platform_id' => $property->platform_id,
                    'name' => $property->name,
                    'key' => $property->key,
                    'platform' => $property->platform ? [
                        'id' => $property->platform->id,
                        'name' => $property->platform->name,
                        'slug' => $property->platform->slug,
                        'type' => $property->platform->type,
                    ] : null,
                ]),
        ]);
    }

    public function categoriesCreate()
    {
        return inertia('Admin/CategoriesForm', [
            'mode' => 'create',
            'category' => null,
            'properties' => Property::query()
                ->with('platform:id,name,slug,type')
                ->orderBy('name')
                ->get(['id', 'platform_id', 'name', 'key'])
                ->map(static fn (Property $property): array => [
                    'id' => $property->id,
                    'platform_id' => $property->platform_id,
                    'name' => $property->name,
                    'key' => $property->key,
                    'platform' => $property->platform ? [
                        'id' => $property->platform->id,
                        'name' => $property->platform->name,
                        'slug' => $property->platform->slug,
                        'type' => $property->platform->type,
                    ] : null,
                ]),
        ]);
    }

    public function categoriesEdit(Category $category)
    {
        $category->load('properties:id');

        return inertia('Admin/CategoriesForm', [
            'mode' => 'edit',
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'active' => (bool) $category->active,
                'property_ids' => $category->properties->pluck('id')->all(),
            ],
            'properties' => Property::query()
                ->with('platform:id,name,slug,type')
                ->orderBy('name')
                ->get(['id', 'platform_id', 'name', 'key'])
                ->map(static fn (Property $property): array => [
                    'id' => $property->id,
                    'platform_id' => $property->platform_id,
                    'name' => $property->name,
                    'key' => $property->key,
                    'platform' => $property->platform ? [
                        'id' => $property->platform->id,
                        'name' => $property->platform->name,
                        'slug' => $property->platform->slug,
                        'type' => $property->platform->type,
                    ] : null,
                ]),
        ]);
    }

    public function configs()
    {
        $configs = Config::query()
            ->orderBy('key')
            ->paginate(20)
            ->through(function (Config $config): array {
                return [
                    'id' => $config->id,
                    'key' => $config->key,
                    'value' => $this->sanitizeConfigValue($config),
                    'description' => $config->description,
                    'is_encrypted' => (bool) $config->is_encrypted,
                    'updated_at' => optional($config->updated_at)?->toISOString(),
                ];
            });

        return inertia('Admin/Configs', [
            'configs' => $configs,
        ]);
    }

    public function configsCreate()
    {
        return inertia('Admin/ConfigsForm', [
            'mode' => 'create',
            'config' => null,
        ]);
    }

    public function configsEdit(Config $config)
    {
        return inertia('Admin/ConfigsForm', [
            'mode' => 'edit',
            'config' => [
                'id' => $config->id,
                'key' => $config->key,
                'value' => $config->value,
                'description' => $config->description,
                'is_encrypted' => (bool) $config->is_encrypted,
            ],
        ]);
    }

    public function usersCreate()
    {
        return inertia('Admin/UsersForm', [
            'mode' => 'create',
            'user' => null,
            'roles' => Role::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug']),
        ]);
    }

    public function usersEdit(User $user)
    {
        $user->load('roles:id');

        return inertia('Admin/UsersForm', [
            'mode' => 'edit',
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => $user->name,
                'email' => $user->email,
                'role_ids' => $user->roles->pluck('id')->all(),
            ],
            'roles' => Role::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug']),
        ]);
    }

    private function sanitizeConfigValue(Config $config): mixed
    {
        if ($config->is_encrypted || $this->isSensitiveKey($config->key)) {
            return '[redacted]';
        }

        return $config->value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach (['secret', 'token', 'password', 'private_key', 'client_secret'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}

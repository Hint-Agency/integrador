<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DirectoGroupMigrationCommand extends Command
{
    protected $signature = 'directogroup:migrate
        {--source=directoGroup : Source legacy connection name or database name}
        {--target=directoGroup_v2 : Target v2 connection name or database name}
        {--access-source=integration_v2 : Source v2 connection/database for roles and permissions}
        {--execute : Write transformed data to the target database}
        {--truncate : Truncate target configuration tables before executing}';

    protected $description = 'Transform directoGroup legacy configuration into the v2 schema without migrating operational history.';

    /**
     * @var array<string, array<int, int>>
     */
    private array $idMap = [
        'platforms' => [],
        'events' => [],
        'properties' => [],
        'categories' => [],
        'event_trigger_groups' => [],
    ];

    /**
     * @var array<string, int>
     */
    private array $counts = [];

    /**
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * @var list<string>
     */
    private array $notices = [];

    public function handle(): int
    {
        $source = $this->runtimeConnection((string) $this->option('source'), 'source');
        $target = $this->runtimeConnection((string) $this->option('target'), 'target');
        $accessSource = $this->runtimeConnection((string) $this->option('access-source'), 'access_source');
        $execute = (bool) $this->option('execute');

        $this->info(($execute ? 'Executing' : 'Dry-running').' directoGroup migration.');
        $this->line('Source: '.$source->getName().' | Target: '.$target->getName());
        $this->line('Access source: '.$accessSource->getName());

        if (! $this->validateSchema($source, $target) || ! $this->validateAccessSchema($accessSource, $target)) {
            $this->renderReport();

            return self::FAILURE;
        }

        if ($execute && (bool) $this->option('truncate')) {
            $this->truncateTarget($target);
        }

        DB::connection($target->getName())->transaction(function () use ($source, $target, $accessSource, $execute): void {
            $this->migratePlatforms($source, $target, $execute);
            $this->migrateCategories($source, $target, $execute);
            $this->migrateEvents($source, $target, $execute);
            $this->migrateProperties($source, $target, $execute);
            $this->migratePropertyEvent($source, $target, $execute);
            $this->migratePropertyRelationships($source, $target, $execute);
            $this->migrateCategoryProperty($source, $target, $execute);
            $this->migrateEventTriggers($source, $target, $execute);
            $this->migrateConfigs($source, $target, $execute);
            $this->migrateAccessData($accessSource, $target, $execute);
        });

        $this->validateMigratedConfiguration($source);
        $this->renderReport();

        return empty($this->warnings) ? self::SUCCESS : self::INVALID;
    }

    private function validateAccessSchema(ConnectionInterface $accessSource, ConnectionInterface $target): bool
    {
        $ok = true;

        foreach (['roles', 'permissions', 'permission_role'] as $table) {
            if (! Schema::connection($accessSource->getName())->hasTable($table)) {
                $this->warnings[] = 'Access source table missing: '.$table;
                $ok = false;
            }

            if (! Schema::connection($target->getName())->hasTable($table)) {
                $this->warnings[] = 'Target access table missing: '.$table;
                $ok = false;
            }
        }

        return $ok;
    }

    private function runtimeConnection(string $nameOrDatabase, string $suffix): ConnectionInterface
    {
        if (Config::has('database.connections.'.$nameOrDatabase)) {
            return DB::connection($nameOrDatabase);
        }

        $baseName = (string) config('database.default');
        $base = config('database.connections.'.$baseName);

        if (! is_array($base)) {
            throw new \RuntimeException('Default database connection is not configured.');
        }

        $connectionName = 'directogroup_migration_'.$suffix;
        $base['database'] = $nameOrDatabase;
        config(['database.connections.'.$connectionName => $base]);
        DB::purge($connectionName);

        return DB::connection($connectionName);
    }

    private function validateSchema(ConnectionInterface $source, ConnectionInterface $target): bool
    {
        $ok = true;
        $sourceTables = [
            'platforms',
            'events',
            'properties',
            'property_event',
            'property_relationships',
            'categories',
            'event_trigger_groups',
            'event_triggers',
            'event_trigger_group_conditions',
            'configs',
        ];
        $targetTables = [
            'platforms',
            'events',
            'properties',
            'property_event',
            'property_relationships',
            'categories',
            'category_property',
            'event_trigger_groups',
            'event_triggers',
            'configs',
        ];

        foreach ($sourceTables as $table) {
            if (! Schema::connection($source->getName())->hasTable($table)) {
                $this->warnings[] = 'Source table missing: '.$table;
                $ok = false;
            }
        }

        foreach ($targetTables as $table) {
            if (! Schema::connection($target->getName())->hasTable($table)) {
                $this->warnings[] = 'Target table missing: '.$table;
                $ok = false;
            }
        }

        return $ok;
    }

    private function truncateTarget(ConnectionInterface $target): void
    {
        Schema::connection($target->getName())->disableForeignKeyConstraints();

        foreach ([
            'event_triggers',
            'event_trigger_group_conditions',
            'event_trigger_groups',
            'property_relationships',
            'property_event',
            'category_property',
            'properties',
            'events',
            'categories',
            'platforms',
            'configs',
        ] as $table) {
            if (Schema::connection($target->getName())->hasTable($table)) {
                $target->table($table)->truncate();
            }
        }

        Schema::connection($target->getName())->enableForeignKeyConstraints();
    }

    private function migratePlatforms(ConnectionInterface $source, ConnectionInterface $target, bool $execute): void
    {
        $rows = $source->table('platforms')->orderBy('id')->get();
        $this->counts['platforms'] = $rows->count();

        foreach ($rows as $row) {
            $data = [
                'name' => $row->name,
                'slug' => $row->slug ?: Str::slug($row->name),
                'type' => $row->type,
                'signature' => $row->signature ?? null,
                'secret_key' => null,
                'credentials' => json_encode((object) []),
                'settings' => json_encode($this->platformSettings($row)),
                'active' => (bool) ($row->active ?? true),
                'created_at' => $row->created_at ?? now(),
                'updated_at' => now(),
            ];

            $this->warnIfLegacySecretsExist('platforms', (int) $row->id, (array) $row);

            if ($execute) {
                $newId = $target->table('platforms')->insertGetId($data);
                $this->idMap['platforms'][(int) $row->id] = (int) $newId;
            } else {
                $this->idMap['platforms'][(int) $row->id] = (int) $row->id;
            }
        }
    }

    private function platformSettings(object $row): array
    {
        $settings = [
            'migration' => [
                'source' => 'directoGroup',
                'legacy_id' => (int) $row->id,
                'credentials_pending' => true,
            ],
        ];

        if (! empty($row->api_url)) {
            $settings['url'] = $row->api_url;
        }

        if (($row->type ?? null) === 'odoo') {
            $settings['odoo'] = [
                'adapter' => 'xml_rpc',
                'models' => [
                    'product' => 'product.product',
                    'subscription_pricing' => 'sale.subscription.pricing',
                    'sale_subscription' => 'dp.sale.subscription',
                ],
                'defaults' => [],
                'catalogs' => [
                    'taxes' => [
                        '16' => 2,
                        '22' => 216,
                        '21' => 217,
                        '10' => 218,
                    ],
                    'uom' => [
                        'Día(s)' => 6,
                        'Hora(s)' => 5,
                        'Unidad de servicio' => 20,
                        'Unidad(es)' => 1,
                    ],
                    'price_currency_properties' => [
                        'USD' => 'hs_price_usd',
                        'UYU' => 'hs_price_uyu',
                    ],
                    'divisions' => [
                        'Ambitone' => 'AmbitOne',
                        'Ambitpro' => 'otros',
                        'Bayer' => 'otros',
                        'Consumibles' => 'consumibles',
                        'Hardware' => 'hardware',
                        'Hardware Haas' => 'otro',
                        'Lightspeed' => 'otros',
                        'Restbar' => 'restbar',
                        'Retailpro' => 'retailpro',
                        'Servicios' => 'otros',
                    ],
                    'sales_teams' => [
                        'Ambit One' => 38,
                        'Retailpro' => 15,
                        'Orquestador' => 41,
                        'Rest Bar' => 14,
                        'Ambit Pro' => 38,
                        'Lightspeed Resto' => 31,
                        'Lightspeed Retail' => 30,
                        'Ambit Retail' => 45,
                        'Retail Pro' => 15,
                    ],
                    'aliases' => [
                        'country_id' => [
                            'México' => ['MX', 'Mexico'],
                            'Mexico' => ['MX', 'México'],
                        ],
                    ],
                ],
                'relational_models' => [
                    'user_id' => 'res.users',
                    'city_id' => 'res.city',
                    'state_id' => 'res.country.state',
                    'country_id' => 'res.country',
                    'company_id' => 'res.company',
                    'currency_id' => 'res.currency',
                    'cost_currency_id' => 'res.currency',
                    'team_id' => 'crm.team',
                    'categ_id' => 'product.category',
                    'product_uom' => 'uom.uom',
                    'uom_id' => 'uom.uom',
                    'uom_po_id' => 'uom.uom',
                    'taxes_id' => 'account.tax',
                    'supplier_taxes_id' => 'account.tax',
                ],
            ];
        }

        return $settings;
    }

    private function migrateCategories(ConnectionInterface $source, ConnectionInterface $target, bool $execute): void
    {
        $rows = $source->table('categories')->orderBy('id')->get();
        $this->counts['categories'] = $rows->count();

        foreach ($rows as $row) {
            $data = [
                'name' => $row->name,
                'slug' => Str::slug($row->name ?: 'category-'.$row->id),
                'description' => null,
                'active' => true,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => now(),
            ];

            if ($execute) {
                $newId = $target->table('categories')->insertGetId($data);
                $this->idMap['categories'][(int) $row->id] = (int) $newId;
            } else {
                $this->idMap['categories'][(int) $row->id] = (int) $row->id;
            }
        }
    }

    private function migrateEvents(ConnectionInterface $source, ConnectionInterface $target, bool $execute): void
    {
        $rows = $source->table('events')->orderBy('id')->get();
        $rows = $rows->reject(fn ($row): bool => (int) $row->id === 32)->values();
        $this->counts['events'] = $rows->count();

        foreach ($rows as $row) {
            $platformId = $this->idMap['platforms'][(int) $row->platform_id] ?? null;
            if (! $platformId) {
                $this->warnings[] = 'Event '.$row->id.' skipped: missing mapped platform '.$row->platform_id;

                continue;
            }

            $mapping = $this->mapEvent((string) ($row->event_type_id ?: $row->name), (string) ($row->type ?? 'webhook'));
            $data = [
                'platform_id' => $platformId,
                'to_event_id' => null,
                'name' => $row->name,
                'event_type_id' => $mapping['event_type_id'],
                'type' => $row->type ?: 'webhook',
                'schedule_expression' => $row->schedule_expression ?? null,
                'last_executed_at' => $row->last_executed_at ?? null,
                'command_sql' => $row->command_sql ?? null,
                'enable_update_hubdb' => (bool) ($row->enable_update_hubdb ?? false),
                'hubdb_table_id' => $row->hubdb_table_id ?? null,
                'subscription_type' => $mapping['subscription_type'],
                'method_name' => $mapping['method_name'],
                'endpoint_api' => $row->endpoint_api ?? null,
                'payload_mapping' => json_encode((object) []),
                'meta' => json_encode([
                    'migration' => [
                        'source' => 'directoGroup',
                        'legacy_id' => (int) $row->id,
                        'legacy_event_type_id' => $row->event_type_id ?? null,
                        'legacy_to_event_id' => $row->to_event_id ?? null,
                    ],
                ]),
                'active' => (bool) ($row->active ?? true),
                'created_at' => $row->created_at ?? now(),
                'updated_at' => now(),
            ];

            if (! $this->eventCanResolve($data['event_type_id'], $data['subscription_type'], $data['method_name'], $platformId, $target, $execute)) {
                $this->warnings[] = 'Event '.$row->id.' has unresolved EventType/method: '.($row->event_type_id ?? $row->name);
            }

            if ($execute) {
                $newId = $target->table('events')->insertGetId($data);
                $this->idMap['events'][(int) $row->id] = (int) $newId;
            } else {
                $this->idMap['events'][(int) $row->id] = (int) $row->id;
            }
        }

        foreach ($rows as $row) {
            if (empty($row->to_event_id) || ! isset($this->idMap['events'][(int) $row->id])) {
                continue;
            }

            $toEventId = $this->idMap['events'][(int) $row->to_event_id] ?? null;
            if (! $toEventId) {
                $this->warnings[] = 'Event '.$row->id.' references missing to_event_id '.$row->to_event_id;

                continue;
            }

            if ($execute) {
                $target->table('events')
                    ->where('id', $this->idMap['events'][(int) $row->id])
                    ->update(['to_event_id' => $toEventId]);
            }
        }

        if ($execute) {
            $this->applyDirectoGroupFlowOverrides($target);
        }
    }

    /**
     * @return array{event_type_id: string, subscription_type: ?string, method_name: ?string}
     */
    private function mapEvent(string $legacyType, string $type): array
    {
        $normalized = strtolower(trim($legacyType));

        return match ($normalized) {
            'hubspot.company.created' => ['event_type_id' => 'company.created', 'subscription_type' => 'company.creation', 'method_name' => 'companyCreatedWebhook'],
            'hubspot.company.updated',
            'hubspot.company.property_changed' => ['event_type_id' => 'company.updated', 'subscription_type' => 'company.propertyChange', 'method_name' => 'companyPropertyChange'],
            'hubspot.deal.property_changed' => ['event_type_id' => 'deal.propertyChange', 'subscription_type' => 'deal.propertyChange', 'method_name' => 'dealPropertyChange'],
            'hubspot.object.property_changed' => ['event_type_id' => 'object.propertyChange', 'subscription_type' => 'object.propertyChange', 'method_name' => 'objectPropertyChange'],
            'hubspot.invoice.property_changed' => ['event_type_id' => 'object.propertyChange', 'subscription_type' => 'invoice.propertyChange', 'method_name' => 'invoicePropertyChange'],
            'hubspot.signed.quotes' => ['event_type_id' => 'quotes.sending_data', 'subscription_type' => 'hubspot.signed.quotes', 'method_name' => 'getSignedQuotes'],
            'hubspot.archived.quotes' => ['event_type_id' => 'quotes.sending_data', 'subscription_type' => 'hubspot.archived.quotes', 'method_name' => 'getArchivedQuotes'],
            'hubspot.update.company' => ['event_type_id' => 'company.updated', 'subscription_type' => null, 'method_name' => 'updateCompany'],
            'hubspot.update.product' => ['event_type_id' => 'product.updated', 'subscription_type' => null, 'method_name' => 'updateProducts'],
            'hubspot.create.product' => ['event_type_id' => 'product.created', 'subscription_type' => null, 'method_name' => 'createProducts'],
            'hubspot.update.object',
            'hubspot.update.object.invoice' => ['event_type_id' => 'invoice.created', 'subscription_type' => null, 'method_name' => 'createOrUpdateInvoiceObject'],
            'createcontactodooreceive' => ['event_type_id' => 'company.created', 'subscription_type' => $normalized, 'method_name' => 'resPartnerCreateOrUpdateContact'],
            'odoo.partner.created.company',
            'odoo.partner.created' => ['event_type_id' => 'company.created', 'subscription_type' => $normalized, 'method_name' => 'resPartnerCreateCompany'],
            'odoo.partner.updated.company',
            'odoo.partner.updated',
            'res.partner' => ['event_type_id' => 'company.updated', 'subscription_type' => $normalized, 'method_name' => 'resPartnerUpdate'],
            'odoo.sync.create.products',
            'odoo.product.created' => ['event_type_id' => 'product.created', 'subscription_type' => $normalized, 'method_name' => 'syncCreateProducts'],
            'odoo.sync.update.products',
            'odoo.product.updated' => ['event_type_id' => 'product.updated', 'subscription_type' => $normalized, 'method_name' => 'syncUpdateProducts'],
            'odoo.create.sale.order',
            'odoo.sale_order.created' => ['event_type_id' => 'sale_order.created', 'subscription_type' => $normalized, 'method_name' => 'createSaleOrder'],
            'odoo.create.sale.subscription' => ['event_type_id' => 'invoice.recurring.created', 'subscription_type' => $normalized, 'method_name' => 'createSaleSubscription'],
            'account.move' => ['event_type_id' => 'invoice.created', 'subscription_type' => 'account.move', 'method_name' => 'accountMoveCreatedUpdated'],
            'odoo.sale_order.canceled' => ['event_type_id' => 'sale_order.created', 'subscription_type' => $normalized, 'method_name' => 'saleOrderCanceled'],
            'odoo.sale_subscription.canceled' => ['event_type_id' => 'sale_order.created', 'subscription_type' => $normalized, 'method_name' => 'saleSubscriptionCanceled'],
            default => ['event_type_id' => $legacyType, 'subscription_type' => $type === 'webhook' ? $legacyType : null, 'method_name' => null],
        };
    }

    private function applyDirectoGroupFlowOverrides(ConnectionInterface $target): void
    {
        $overrides = [
            24 => [
                'name' => 'Source Company Updated',
                'active' => true,
                'to_legacy_id' => 25,
                'meta' => [
                    'flow' => 'company_sync',
                    'role' => 'source_company_updated',
                    'target_platform' => 'odoo',
                ],
            ],
            25 => [
                'name' => 'Create/Update Destination Company',
                'active' => true,
                'to_legacy_id' => 26,
                'meta' => [
                    'flow' => 'company_sync',
                    'role' => 'create_update_destination_company',
                    'target_model' => 'res.partner',
                    'contact_type' => 'company',
                ],
            ],
            26 => [
                'name' => 'Write Back Source Company',
                'active' => true,
                'to_legacy_id' => null,
                'meta' => [
                    'flow' => 'company_sync',
                    'role' => 'write_back_source_company',
                ],
            ],
            29 => [
                'name' => 'Source Company Created',
                'active' => true,
                'to_legacy_id' => 25,
                'meta' => [
                    'flow' => 'company_sync',
                    'role' => 'source_company_created',
                    'target_platform' => 'odoo',
                ],
            ],
            31 => [
                'name' => 'Create/Update Destination Contact',
                'active' => true,
                'to_legacy_id' => 26,
                'method_name' => 'resPartnerCreateOrUpdateContact',
                'meta' => [
                    'flow' => 'contact_sync',
                    'role' => 'create_update_destination_contact',
                    'target_model' => 'res.partner',
                    'contact_type' => 'contact',
                ],
            ],
            33 => [
                'name' => 'Create Destination Product',
                'active' => true,
                'to_legacy_id' => null,
                'meta' => [
                    'flow' => 'product_sync',
                    'role' => 'create_destination_product',
                ],
            ],
            34 => [
                'name' => 'Sync Source Products',
                'active' => true,
                'to_legacy_id' => 35,
                'meta' => [
                    'flow' => 'product_sync',
                    'role' => 'fetch_products',
                    'matching' => ['sku', 'default_code', 'odoo_id'],
                ],
            ],
            35 => [
                'name' => 'Update Destination Product',
                'active' => true,
                'to_legacy_id' => 33,
                'meta' => [
                    'flow' => 'product_sync',
                    'role' => 'update_destination_product',
                    'fallback_event' => 'create_destination_product',
                    'matching' => ['sku', 'default_code', 'odoo_id'],
                ],
            ],
            39 => [
                'name' => 'Fetch Signed Quotes',
                'active' => true,
                'to_legacy_id' => 40,
                'meta' => [
                    'flow' => 'signed_quotes',
                    'role' => 'fetch_signed_quotes',
                    'target_platform' => 'odoo',
                    'internal_jobs' => [
                        'BuildCanonicalPayloadJob',
                        'ValidateEntitiesJob',
                        'ResolveAssociationsJob',
                        'CreateOrUpdateEntityJob',
                        'CreateQuoteJob',
                    ],
                ],
            ],
            40 => [
                'name' => 'Create Destination Quote/Subscription',
                'active' => true,
                'to_legacy_id' => null,
                'meta' => [
                    'flow' => 'signed_quotes',
                    'role' => 'create_destination_quote_subscription',
                    'target_model' => 'dp.sale.subscription',
                ],
            ],
            41 => [
                'name' => 'Fetch Archived Quotes',
                'active' => true,
                'to_legacy_id' => 42,
                'meta' => [
                    'flow' => 'archived_quotes',
                    'role' => 'fetch_archived_quotes',
                ],
            ],
            42 => [
                'name' => 'Cancel Destination Quote/Subscription',
                'active' => true,
                'to_legacy_id' => null,
                'method_name' => 'saleSubscriptionCanceled',
                'meta' => [
                    'flow' => 'archived_quotes',
                    'role' => 'cancel_destination_quote_subscription',
                    'target_model' => 'dp.sale.subscription',
                    'lookup_priority' => ['x_studio_quote_id', 'folio', 'odoo_id'],
                ],
            ],
            43 => [
                'name' => 'Invoice Webhook Received',
                'active' => true,
                'to_legacy_id' => 44,
                'meta' => [
                    'flow' => 'invoice_sync',
                    'role' => 'invoice_webhook_received',
                    'source_model' => 'account.move',
                ],
            ],
            44 => [
                'name' => 'Create/Update Source Invoice Object',
                'active' => true,
                'to_legacy_id' => null,
                'event_type_id' => 'invoice.created',
                'method_name' => 'createOrUpdateInvoiceObject',
                'meta' => [
                    'flow' => 'invoice_sync',
                    'role' => 'create_update_source_invoice_object',
                    'invoice_object_type' => 'p143559608_facturas_erp',
                    'invoice_match_property' => 'odoo_id',
                    'invoice_match_fallback_properties' => ['name'],
                    'invoice_to_deal_association_type_id' => 39,
                ],
            ],
        ];

        foreach ($overrides as $legacyId => $override) {
            $eventId = $this->idMap['events'][$legacyId] ?? null;
            if (! $eventId) {
                continue;
            }

            $row = $target->table('events')->where('id', $eventId)->first(['meta']);
            $meta = json_decode((string) ($row->meta ?? ''), true);
            if (! is_array($meta)) {
                $meta = [];
            }

            $data = [
                'name' => $override['name'],
                'active' => (bool) $override['active'],
                'to_event_id' => isset($override['to_legacy_id'])
                    ? ($override['to_legacy_id'] === null ? null : ($this->idMap['events'][(int) $override['to_legacy_id']] ?? null))
                    : null,
                'meta' => json_encode(array_replace_recursive($meta, [
                    'directogroup_v2_flow' => $override['meta'],
                ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ];

            if (isset($override['event_type_id'])) {
                $data['event_type_id'] = $override['event_type_id'];
            }

            if (isset($override['method_name'])) {
                $data['method_name'] = $override['method_name'];
            }

            $target->table('events')->where('id', $eventId)->update($data);
        }

        $this->ensureSignedQuoteWritebackEvent($target);
        $this->ensureSignedQuoteSubscriptionMappings($target);
        $this->ensureProductCurrencyPriceMappings($target);
        $this->ensureArchivedQuoteCancellationWritebackEvent($target);
    }

    private function ensureSignedQuoteWritebackEvent(ConnectionInterface $target): void
    {
        $hubspotPlatformId = $target->table('platforms')->where('type', 'hubspot')->orderBy('id')->value('id');
        $subscriptionEventId = $this->idMap['events'][40] ?? null;

        if (! $hubspotPlatformId || ! $subscriptionEventId) {
            return;
        }

        $meta = [
            'object_type' => 'quotes',
            'directogroup_v2_flow' => [
                'flow' => 'signed_quotes',
                'role' => 'write_back_source_quote',
                'object_type' => 'quotes',
                'marker_properties' => [
                    'sync_status_odoo',
                    'last_sync_odoo',
                    'odoo_id',
                    'last_error_odoo',
                ],
            ],
        ];

        $writebackEvent = $target->table('events')
            ->where('platform_id', $hubspotPlatformId)
            ->where('name', 'Write Back Source Quote')
            ->first();

        $data = [
            'platform_id' => $hubspotPlatformId,
            'to_event_id' => null,
            'name' => 'Write Back Source Quote',
            'event_type_id' => 'object.updated',
            'type' => 'node',
            'schedule_expression' => null,
            'last_executed_at' => null,
            'command_sql' => null,
            'enable_update_hubdb' => false,
            'hubdb_table_id' => null,
            'subscription_type' => null,
            'method_name' => 'updateQuoteObject',
            'endpoint_api' => null,
            'payload_mapping' => json_encode((object) []),
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'active' => true,
            'updated_at' => now(),
        ];

        if ($writebackEvent) {
            $target->table('events')->where('id', $writebackEvent->id)->update($data);
            $writebackEventId = (int) $writebackEvent->id;
        } else {
            $data['created_at'] = now();
            $writebackEventId = (int) $target->table('events')->insertGetId($data);
            $this->counts['events'] = ($this->counts['events'] ?? 0) + 1;
        }

        $target->table('events')
            ->where('id', $subscriptionEventId)
            ->update([
                'to_event_id' => $writebackEventId,
                'updated_at' => now(),
            ]);
    }

    private function ensureSignedQuoteSubscriptionMappings(ConnectionInterface $target): void
    {
        $eventId = $this->idMap['events'][40] ?? null;
        if (! $eventId) {
            return;
        }

        $hubspotPlatformId = $target->table('platforms')->where('type', 'hubspot')->orderBy('id')->value('id');
        $odooPlatformId = $target->table('platforms')->where('type', 'odoo')->orderBy('id')->value('id');

        $sourcePropertyId = $target->table('properties')->where('key', 'hs_terms')->value('id');
        $targetPropertyId = $target->table('properties')->where('key', 'note')->value('id');
        if ($sourcePropertyId && $targetPropertyId) {
            $this->ensureMigratedRelationship(
                $target,
                (int) $eventId,
                (int) $sourcePropertyId,
                (int) $targetPropertyId,
                'hs_terms',
                [
                    'scope' => 'sale_subscription',
                    'transform' => 'html_to_text',
                ]
            );
        }

        if (! $hubspotPlatformId || ! $odooPlatformId) {
            return;
        }

        $currencySourcePropertyId = $this->ensureMigratedProperty(
            $target,
            (int) $hubspotPlatformId,
            'Moneda de la cotización',
            'hs_currency',
            'string',
            ['object_type' => 'quotes']
        );
        $currencyTargetPropertyId = $this->ensureMigratedProperty(
            $target,
            (int) $odooPlatformId,
            'Moneda',
            'currency_id',
            'integer',
            [
                'odoo_model' => 'res.currency',
                'field_type' => 'many2one',
            ]
        );

        $this->ensureMigratedRelationship(
            $target,
            (int) $eventId,
            $currencySourcePropertyId,
            $currencyTargetPropertyId,
            'hs_currency',
            [
                'scope' => 'sale_subscription',
                'migration_adjustment' => [
                    'reason' => 'Resolve HubSpot quote currency into Odoo dp.sale.subscription.currency_id.',
                ],
            ]
        );
    }

    private function ensureArchivedQuoteCancellationWritebackEvent(ConnectionInterface $target): void
    {
        $hubspotPlatformId = $target->table('platforms')->where('type', 'hubspot')->orderBy('id')->value('id');
        $cancelEventId = $this->idMap['events'][42] ?? null;

        if (! $hubspotPlatformId || ! $cancelEventId) {
            return;
        }

        $meta = [
            'object_type' => 'quotes',
            'directogroup_v2_flow' => [
                'flow' => 'archived_quotes',
                'role' => 'write_back_archived_quote_cancellation',
                'object_type' => 'quotes',
                'marker_properties' => [
                    'sync_status_odoo',
                    'last_sync_odoo',
                    'last_error_odoo',
                ],
            ],
        ];

        $writebackEvent = $target->table('events')
            ->where('platform_id', $hubspotPlatformId)
            ->where('name', 'Write Back Archived Quote Cancellation')
            ->first();

        $data = [
            'platform_id' => $hubspotPlatformId,
            'to_event_id' => null,
            'name' => 'Write Back Archived Quote Cancellation',
            'event_type_id' => 'object.updated',
            'type' => 'node',
            'schedule_expression' => null,
            'last_executed_at' => null,
            'command_sql' => null,
            'enable_update_hubdb' => false,
            'hubdb_table_id' => null,
            'subscription_type' => null,
            'method_name' => 'writeBackArchivedQuoteCancellation',
            'endpoint_api' => null,
            'payload_mapping' => json_encode((object) []),
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'active' => true,
            'updated_at' => now(),
        ];

        if ($writebackEvent) {
            $target->table('events')->where('id', $writebackEvent->id)->update($data);
            $writebackEventId = (int) $writebackEvent->id;
        } else {
            $data['created_at'] = now();
            $writebackEventId = (int) $target->table('events')->insertGetId($data);
            $this->counts['events'] = ($this->counts['events'] ?? 0) + 1;
        }

        $target->table('events')
            ->where('id', $cancelEventId)
            ->update([
                'to_event_id' => $writebackEventId,
                'updated_at' => now(),
            ]);
    }

    private function ensureProductCurrencyPriceMappings(ConnectionInterface $target): void
    {
        $eventId = $this->idMap['events'][34] ?? null;
        $odooPlatformId = $target->table('platforms')->where('type', 'odoo')->orderBy('id')->value('id');
        $hubspotPlatformId = $target->table('platforms')->where('type', 'hubspot')->orderBy('id')->value('id');

        if (! $eventId || ! $odooPlatformId || ! $hubspotPlatformId) {
            return;
        }

        foreach ([
            'USD' => ['key' => 'hs_price_usd', 'name' => 'Precio USD'],
            'UYU' => ['key' => 'hs_price_uyu', 'name' => 'Precio UYU'],
        ] as $currency => $property) {
            $sourcePropertyId = $this->ensureMigratedProperty(
                $target,
                (int) $odooPlatformId,
                $property['name'].' calculado',
                $property['key'],
                'float',
                [
                    'computed' => true,
                    'source' => 'odoo.product.pricelist.item',
                    'currency' => $currency,
                ]
            );
            $targetPropertyId = $this->ensureMigratedProperty(
                $target,
                (int) $hubspotPlatformId,
                $property['name'],
                $property['key'],
                'float',
                [
                    'object_type' => 'products',
                    'currency' => $currency,
                ]
            );

            $this->ensureMigratedRelationship(
                $target,
                (int) $eventId,
                $sourcePropertyId,
                $targetPropertyId,
                $property['key'],
                [
                    'computed' => [
                        'source' => 'list_prices',
                        'currency' => $currency,
                        'settings_path' => 'odoo.catalogs.price_currency_properties',
                    ],
                    'migration_adjustment' => [
                        'reason' => 'Map Odoo pricelist currency price to HubSpot product price property.',
                    ],
                ]
            );
        }
    }

    private function ensureMigratedProperty(
        ConnectionInterface $target,
        int $platformId,
        string $name,
        string $key,
        string $type,
        array $meta = []
    ): int {
        $existing = $target->table('properties')
            ->where('platform_id', $platformId)
            ->where('key', $key)
            ->first(['id', 'meta']);

        $encodedMeta = json_encode(array_replace_recursive([
            'migration' => [
                'source' => 'directoGroup',
                'generated_by' => 'directogroup_v2_flow_overrides',
            ],
        ], $meta), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $data = [
            'platform_id' => $platformId,
            'name' => $name,
            'key' => $key,
            'type' => $type,
            'required' => false,
            'active' => true,
            'meta' => $encodedMeta,
            'updated_at' => now(),
        ];

        if ($existing) {
            $target->table('properties')->where('id', $existing->id)->update($data);

            return (int) $existing->id;
        }

        $data['created_at'] = now();
        $this->counts['properties'] = ($this->counts['properties'] ?? 0) + 1;

        return (int) $target->table('properties')->insertGetId($data);
    }

    private function ensureMigratedRelationship(
        ConnectionInterface $target,
        int $eventId,
        int $sourcePropertyId,
        int $targetPropertyId,
        string $mappingKey,
        array $meta = []
    ): void {
        $existing = $target->table('property_relationships')
            ->where('event_id', $eventId)
            ->where('property_id', $sourcePropertyId)
            ->where('related_property_id', $targetPropertyId)
            ->first(['id']);

        $data = [
            'mapping_key' => $mappingKey,
            'active' => true,
            'meta' => json_encode(array_replace_recursive([
                'migration' => [
                    'source' => 'directoGroup',
                    'generated_by' => 'directogroup_v2_flow_overrides',
                ],
            ], $meta), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ];

        if ($existing) {
            $target->table('property_relationships')->where('id', $existing->id)->update($data);

            return;
        }

        $target->table('property_relationships')->insert([
            ...$data,
            'event_id' => $eventId,
            'property_id' => $sourcePropertyId,
            'related_property_id' => $targetPropertyId,
            'created_at' => now(),
        ]);

        $this->counts['property_relationships'] = ($this->counts['property_relationships'] ?? 0) + 1;
    }

    private function eventCanResolve(string $eventTypeId, ?string $subscriptionType, ?string $methodName, int $platformId, ConnectionInterface $target, bool $execute): bool
    {
        if ($methodName !== null) {
            return true;
        }

        if (! $execute) {
            return Event::query()->make([
                'event_type_id' => $eventTypeId,
                'subscription_type' => $subscriptionType,
            ])->getEventClass() !== null;
        }

        $platform = $target->table('platforms')->where('id', $platformId)->first();
        if (! $platform) {
            return false;
        }

        $event = Event::query()->make([
            'event_type_id' => $eventTypeId,
            'subscription_type' => $subscriptionType,
        ]);
        $event->setRelation('platform', new \App\Models\Platform((array) $platform));

        return $event->getEventClass() !== null;
    }

    private function migrateProperties(ConnectionInterface $source, ConnectionInterface $target, bool $execute): void
    {
        $rows = $source->table('properties')->orderBy('id')->get();
        $this->counts['properties'] = $rows->count();

        foreach ($rows as $row) {
            $legacyPlatformId = isset($row->platform_id) && $row->platform_id
                ? (int) $row->platform_id
                : $this->inferLegacyPropertyPlatformId($source, (int) $row->id);
            $platformId = $legacyPlatformId ? ($this->idMap['platforms'][$legacyPlatformId] ?? null) : null;
            if (! $platformId) {
                $this->warnings[] = 'Property '.$row->id.' skipped: missing mapped platform.';

                continue;
            }

            $data = [
                'platform_id' => $platformId,
                'name' => $row->display_name ?? $row->description ?? $row->name,
                'key' => $row->name,
                'type' => $row->type ?: 'string',
                'required' => (bool) ($row->required ?? false),
                'active' => (bool) ($row->active ?? true),
                'meta' => json_encode([
                    'description' => $row->description ?? null,
                    'legacy_display_name' => $row->display_name ?? null,
                    'migration' => [
                        'source' => 'directoGroup',
                        'legacy_id' => (int) $row->id,
                        'legacy_platform_id' => $legacyPlatformId,
                        'platform_inferred' => empty($row->platform_id),
                    ],
                ]),
                'created_at' => $row->created_at ?? now(),
                'updated_at' => now(),
            ];

            if ($execute) {
                $newId = $target->table('properties')->insertGetId($data);
                $this->idMap['properties'][(int) $row->id] = (int) $newId;
            } else {
                $this->idMap['properties'][(int) $row->id] = (int) $row->id;
            }
        }
    }

    private function inferLegacyPropertyPlatformId(ConnectionInterface $source, int $propertyId): ?int
    {
        $platformIds = collect()
            ->merge(
                $source->table('property_event')
                    ->join('events', 'events.id', '=', 'property_event.event_id')
                    ->where('property_event.property_id', $propertyId)
                    ->pluck('events.platform_id')
            )
            ->merge(
                $source->table('property_relationships')
                    ->join('events', 'events.id', '=', 'property_relationships.event_id')
                    ->where('property_relationships.property_id', $propertyId)
                    ->pluck('events.platform_id')
            )
            ->merge(
                $source->table('property_relationships')
                    ->join('events', 'events.id', '=', 'property_relationships.event_id')
                    ->where('property_relationships.related_property_id', $propertyId)
                    ->pluck('events.platform_id')
            );

        $platformId = $platformIds
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        return is_numeric($platformId) ? (int) $platformId : null;
    }

    private function migratePropertyEvent(ConnectionInterface $source, ConnectionInterface $target, bool $execute): void
    {
        $rows = $source->table('property_event')->orderBy('event_id')->orderBy('property_id')->get();
        $rows = $rows->reject(fn ($row): bool => (int) $row->event_id === 32)->values();
        $this->counts['property_event'] = $rows->count();

        foreach ($rows as $row) {
            $eventId = $this->idMap['events'][(int) $row->event_id] ?? null;
            $propertyId = $this->idMap['properties'][(int) $row->property_id] ?? null;

            if (! $eventId || ! $propertyId) {
                $this->warnings[] = 'property_event event '.$row->event_id.' property '.$row->property_id.' skipped: missing event/property mapping.';

                continue;
            }

            if ($execute) {
                $target->table('property_event')->updateOrInsert(
                    ['event_id' => $eventId, 'property_id' => $propertyId],
                    ['created_at' => $row->created_at ?? now(), 'updated_at' => now()]
                );
            }
        }
    }

    private function migratePropertyRelationships(ConnectionInterface $source, ConnectionInterface $target, bool $execute): void
    {
        $rows = $source->table('property_relationships')->get();
        $rows = $rows->reject(fn ($row): bool => (int) $row->event_id === 32)->values();
        $this->counts['property_relationships'] = $rows->count();

        foreach ($rows as $row) {
            $eventId = $this->idMap['events'][(int) $row->event_id] ?? null;
            $propertyId = $this->idMap['properties'][(int) $row->property_id] ?? null;
            $relatedPropertyId = $this->idMap['properties'][(int) $row->related_property_id] ?? null;

            if (! $eventId || ! $propertyId || ! $relatedPropertyId) {
                $this->warnings[] = 'property_relationship skipped: missing mapped IDs for legacy event '.$row->event_id;

                continue;
            }

            if ($execute) {
                $sourceKey = $target->table('properties')->where('id', $propertyId)->value('key');
                $targetKey = $target->table('properties')->where('id', $relatedPropertyId)->value('key');

                $target->table('property_relationships')->insert([
                    'event_id' => $eventId,
                    'property_id' => $propertyId,
                    'related_property_id' => $relatedPropertyId,
                    'mapping_key' => $this->transformedRelationshipMappingKey($row, $sourceKey, $targetKey),
                    'active' => true,
                    'meta' => json_encode(array_replace_recursive([
                        'migration' => [
                            'source' => 'directoGroup',
                            'legacy_event_id' => (int) $row->event_id,
                            'legacy_property_id' => (int) $row->property_id,
                            'legacy_related_property_id' => (int) $row->related_property_id,
                        ],
                    ], $this->transformedRelationshipMeta($row, $sourceKey, $targetKey))),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function transformedRelationshipMappingKey(object $row, mixed $sourceKey, mixed $targetKey): ?string
    {
        if ((int) $row->event_id === 34
            && $sourceKey === 'id'
            && $targetKey === 'odoo_id') {
            return 'product_tmpl_id.0';
        }

        if ((int) $row->event_id === 34
            && $sourceKey === 'uom_id.1'
            && $targetKey === 'unidad_de_medida') {
            return 'uom_id.0';
        }

        return null;
    }

    private function transformedRelationshipMeta(object $row, mixed $sourceKey, mixed $targetKey): array
    {
        if ((int) $row->event_id === 34
            && $sourceKey === 'uom_id.1'
            && $targetKey === 'unidad_de_medida') {
            return [
                'catalog' => [
                    'platform' => 'source',
                    'path' => 'odoo.catalogs.uom',
                    'match' => 'value',
                    'output' => 'key',
                ],
            ];
        }

        return [];
    }

    private function migrateCategoryProperty(ConnectionInterface $source, ConnectionInterface $target, bool $execute): void
    {
        if (! Schema::connection($source->getName())->hasTable('category_property')) {
            $rows = $source->table('properties')->whereNotNull('category_id')->get(['id', 'category_id']);
        } else {
            $rows = $source->table('category_property')->get();
        }

        $this->counts['category_property'] = $rows->count();

        foreach ($rows as $row) {
            $categoryId = $this->idMap['categories'][(int) $row->category_id] ?? null;
            $propertyId = $this->idMap['properties'][(int) $row->property_id] ?? $this->idMap['properties'][(int) $row->id] ?? null;

            if (! $categoryId || ! $propertyId) {
                continue;
            }

            if ($execute) {
                $target->table('category_property')->updateOrInsert(
                    ['category_id' => $categoryId, 'property_id' => $propertyId],
                    ['created_at' => $row->created_at ?? now(), 'updated_at' => now()]
                );
            }
        }
    }

    private function migrateEventTriggers(ConnectionInterface $source, ConnectionInterface $target, bool $execute): void
    {
        $groups = $source->table('event_trigger_groups')->orderBy('id')->get();
        $conditions = $source->table('event_trigger_group_conditions')->orderBy('id')->get();
        $legacyTriggers = $source->table('event_triggers')->get()->keyBy('id');
        $legacyProperties = $source->table('properties')->get()->keyBy('id');

        $this->counts['event_trigger_groups'] = $groups->count();
        $this->counts['event_triggers'] = $legacyTriggers->count();
        $this->counts['event_trigger_group_conditions'] = $conditions->count();

        foreach ($groups as $group) {
            $eventId = $this->idMap['events'][(int) $group->event_id] ?? null;
            if (! $eventId) {
                $this->warnings[] = 'Trigger group '.$group->id.' skipped: missing mapped event.';

                continue;
            }

            $data = [
                'event_id' => $eventId,
                'name' => $group->name ?: 'Legacy group '.$group->id,
                'operator' => strtolower((string) ($group->logical_operator ?? 'AND')),
                'active' => (bool) ($group->active ?? true),
                'created_at' => $group->created_at ?? now(),
                'updated_at' => now(),
            ];

            if ($execute) {
                $newId = $target->table('event_trigger_groups')->insertGetId($data);
                $this->idMap['event_trigger_groups'][(int) $group->id] = (int) $newId;
            } else {
                $this->idMap['event_trigger_groups'][(int) $group->id] = (int) $group->id;
            }
        }

        $conditionsByTrigger = $conditions->keyBy('event_trigger_id');

        foreach ($legacyTriggers as $legacyTrigger) {
            $condition = $conditionsByTrigger->get($legacyTrigger->id);
            $groupId = $condition ? ($this->idMap['event_trigger_groups'][(int) $condition->trigger_group_id] ?? null) : null;

            $mappedPropertyId = $this->idMap['properties'][(int) $legacyTrigger->property_id] ?? null;
            $legacyProperty = $legacyProperties[(int) $legacyTrigger->property_id] ?? null;

            if (! $mappedPropertyId || ! $legacyProperty) {
                $this->warnings[] = 'Trigger '.$legacyTrigger->id.' skipped: missing property.';

                continue;
            }

            $legacyEventId = $condition
                ? ($groups->firstWhere('id', $condition->trigger_group_id)->event_id ?? null)
                : $this->inferLegacyTriggerEventId($source, (int) $legacyTrigger->property_id);
            $groupEventId = $legacyEventId ? ($this->idMap['events'][(int) $legacyEventId] ?? null) : null;

            if (! $groupEventId) {
                $this->warnings[] = 'Trigger '.$legacyTrigger->id.' skipped: unable to infer event.';

                continue;
            }

            if ($execute) {
                $target->table('event_triggers')->insert([
                    'event_id' => $groupEventId,
                    'event_trigger_group_id' => $groupId,
                    'field' => $legacyProperty->name,
                    'operator' => $legacyTrigger->operator,
                    'value' => json_encode($legacyTrigger->value),
                    'active' => (bool) ($legacyTrigger->active ?? true),
                    'created_at' => $legacyTrigger->created_at ?? now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function inferLegacyTriggerEventId(ConnectionInterface $source, int $propertyId): ?int
    {
        $eventId = $source->table('property_event')
            ->join('events', 'events.id', '=', 'property_event.event_id')
            ->where('property_event.property_id', $propertyId)
            ->where('events.active', true)
            ->orderBy('events.id')
            ->value('events.id');

        return is_numeric($eventId) ? (int) $eventId : null;
    }

    private function migrateConfigs(ConnectionInterface $source, ConnectionInterface $target, bool $execute): void
    {
        $rows = $source->table('configs')->get();
        $this->counts['configs'] = $rows->count();

        foreach ($rows as $row) {
            $value = [
                'company_name' => $row->company_name ?? null,
                'logo' => $row->logo ?? null,
            ];

            if ($this->containsSensitiveValue($value)) {
                $this->warnings[] = 'Config '.$row->id.' skipped: possible sensitive value.';

                continue;
            }

            if ($execute) {
                $target->table('configs')->updateOrInsert(
                    ['key' => 'legacy.config.'.$row->id],
                    [
                        'value' => json_encode($value),
                        'description' => 'Migrated non-sensitive legacy config.',
                        'is_encrypted' => false,
                        'created_at' => $row->created_at ?? now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    private function migrateAccessData(ConnectionInterface $accessSource, ConnectionInterface $target, bool $execute): void
    {
        $roles = $accessSource->table('roles')->orderBy('id')->get();
        $permissions = $accessSource->table('permissions')->orderBy('id')->get();
        $permissionRoles = $accessSource->table('permission_role')->orderBy('role_id')->orderBy('permission_id')->get();

        $this->counts['roles'] = $roles->count();
        $this->counts['permissions'] = $permissions->count();
        $this->counts['permission_role'] = $permissionRoles->count();

        if (! $execute) {
            return;
        }

        $target->table('role_user')->delete();
        $target->table('permission_role')->delete();
        $target->table('permissions')->delete();
        $target->table('roles')->delete();

        $roleMap = [];
        foreach ($roles as $role) {
            $roleMap[(int) $role->id] = (int) $target->table('roles')->insertGetId([
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'created_at' => $role->created_at ?? now(),
                'updated_at' => $role->updated_at ?? now(),
            ]);
        }

        $permissionMap = [];
        foreach ($permissions as $permission) {
            $permissionMap[(int) $permission->id] = (int) $target->table('permissions')->insertGetId([
                'name' => $permission->name,
                'slug' => $permission->slug,
                'description' => $permission->description,
                'created_at' => $permission->created_at ?? now(),
                'updated_at' => $permission->updated_at ?? now(),
            ]);
        }

        foreach ($permissionRoles as $permissionRole) {
            $roleId = $roleMap[(int) $permissionRole->role_id] ?? null;
            $permissionId = $permissionMap[(int) $permissionRole->permission_id] ?? null;

            if (! $roleId || ! $permissionId) {
                $this->warnings[] = 'permission_role skipped: missing mapped role/permission.';

                continue;
            }

            $target->table('permission_role')->insert([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
                'created_at' => $permissionRole->created_at ?? now(),
                'updated_at' => $permissionRole->updated_at ?? now(),
            ]);
        }

        $superAdminRoleId = $target->table('roles')->where('slug', 'superadmin')->value('id');
        $superAdminUserId = $target->table('users')
            ->whereIn('email', [env('SUPERADMIN_EMAIL', 'carlos91rubio@gmail.com'), env('ADMIN_EMAIL', 'admin@example.com')])
            ->orderByRaw('FIELD(email, ?, ?)', [env('SUPERADMIN_EMAIL', 'carlos91rubio@gmail.com'), env('ADMIN_EMAIL', 'admin@example.com')])
            ->value('id');

        if ($superAdminRoleId && $superAdminUserId) {
            $target->table('role_user')->insertOrIgnore([
                'role_id' => $superAdminRoleId,
                'user_id' => $superAdminUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->counts['role_user'] = 1;
        }
    }

    private function validateMigratedConfiguration(ConnectionInterface $source): void
    {
        $expected = [
            'platforms' => 2,
            'events' => 15,
            'properties' => 152,
            'property_relationships' => 95,
            'property_event' => 98,
            'event_triggers' => 5,
            'categories' => 4,
        ];

        foreach ($expected as $key => $count) {
            if (($this->counts[$key] ?? null) !== $count) {
                $this->warnings[] = sprintf('Expected %d %s, found %d.', $count, $key, $this->counts[$key] ?? 0);
            }
        }

        foreach (['records', 'webhook_calls', 'jobs', 'failed_jobs', 'sessions', 'cache'] as $excluded) {
            if (Schema::connection($source->getName())->hasTable($excluded)) {
                $this->line('Excluded operational table present in source: '.$excluded);
            }
        }
    }

    private function warnIfLegacySecretsExist(string $table, int $id, array $row): void
    {
        foreach (['api_token', 'privateKey', 'consumerSecret', 'password', 'accessToken', 'refreshToken', 'secret_key'] as $key) {
            if (! empty($row[$key])) {
                $this->notices[] = $table.' '.$id.' contains legacy secret column '.$key.'; value was not migrated.';
            }
        }
    }

    private function containsSensitiveValue(mixed $value): bool
    {
        $encoded = strtolower(json_encode($value) ?: '');

        foreach (['pat-', 'api_token', 'privatekey', 'consumersecret', 'password'] as $needle) {
            if (str_contains($encoded, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function renderReport(): void
    {
        $this->newLine();
        $this->info('Migration report');

        foreach ($this->counts as $key => $count) {
            $this->line($key.' -- STATUS: '.($count > 0 ? 'VERIFIED' : 'PENDING').' ('.$count.')');
        }

        if ($this->warnings === []) {
            $this->line('warnings -- STATUS: VERIFIED (0)');
        } else {
            $this->warn('warnings -- STATUS: BLOCKED ('.count($this->warnings).')');
            foreach ($this->warnings as $warning) {
                $this->line('- '.$warning);
            }
        }

        if ($this->notices === []) {
            $this->line('notices -- STATUS: VERIFIED (0)');

            return;
        }

        $this->line('notices -- STATUS: VERIFIED ('.count($this->notices).')');
        foreach ($this->notices as $notice) {
            $this->line('- '.$notice);
        }
    }
}

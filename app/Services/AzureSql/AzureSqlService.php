<?php

namespace App\Services\AzureSql;

use App\Models\Event;
use App\Models\Platform;
use App\Models\PropertyRelationship;
use App\Models\Record;
use App\Services\Base\BaseService;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AzureSqlService extends BaseService
{
    private const RUNTIME_CONNECTION = 'azure_sql_runtime';

    /**
     * @var array<string, list<string>>
     */
    private const UPSERTABLE_COLUMNS = [
        'inventtable' => [
            'itemid',
            'ProductName',
            'Categoria',
            'Subcategoria',
            'Subcategoria_1',
            'Subcategoria_2',
            'Marca',
            'P_Empaque',
            'taxpackagingqty',
            'kcp_grcor_taxpackagingqty',
            'unitid',
            'Inventario',
            'modifieddatetime',
        ],
        'custtable' => [
            'accountnum',
            'custname',
            'Segmen ID',
            'Sub-Segmen ID',
            'Telefono',
            'Correo',
            'Equipo_Ventas',
            'Sales District ID',
            'Empleado_responsable',
            'address',
            'RNC',
            'modifieddatetime',
        ],
        'contactos_cl' => [
            'accountnum',
            'modifieddatetime',
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const MODIFIED_FILTER_COLUMNS = [
        'inventtable' => 'modifieddatetime',
        'custtable' => 'modifieddatetime',
        'contactos_cl' => 'modifieddatetime',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const NUMERIC_COLUMNS = [
        'inventtable' => [
            'P_Empaque',
            'taxpackagingqty',
            'kcp_grcor_taxpackagingqty',
            'Inventario',
        ],
        'custtable' => [],
        'contactos_cl' => [],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const WRITABLE_COLUMNS = [
        'custtable' => [
            'custname',
            'Cadenas_Empresas',
            'Segmen ID',
            'Sub-Segmen ID',
            'Telefono',
            'Correo',
            'Equipo_Ventas',
            'Sales District ID',
            'Empleado_responsable',
            'address',
            'RNC',
        ],
        'contactos_cl' => [
            'locator',
            'Tipo',
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const DEFAULT_KEY_COLUMNS = [
        'custtable' => ['accountnum'],
        'contactos_cl' => ['accountnum', 'Tipo'],
    ];

    public function __construct(
        Platform $platform,
        ?Event $event = null,
        ?Record $record = null,
        protected ?HubspotApiServiceRefactored $hubspotApi = null
    ) {
        parent::__construct($platform, $event, $record);

        $this->applyHubspotRuntimeConfig();
        $this->hubspotApi ??= app(HubspotApiServiceRefactored::class);
    }

    public function testConnection(): array
    {
        try {
            $connection = $this->makeConnection();
            $result = $connection->select('SELECT 1 as ok');

            return [
                'success' => true,
                'message' => 'Azure SQL connection validated.',
                'data' => [
                    'driver' => self::RUNTIME_CONNECTION,
                    'rows_returned' => count($result),
                    'config' => $this->sanitizedConnectionDetails(),
                ],
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Azure SQL connection failed.',
                'data' => [
                    'config' => $this->sanitizedConnectionDetails(),
                ],
                'error' => [
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
            ];
        }
    }

    public function syncProducts(array $payload = []): array
    {
        return $this->syncTable(
            table: 'inventtable',
            hubspotObjectType: 'products',
            defaultQuery: $this->buildModifiedDatetimeDefaultQuery('inventtable', $payload),
            payload: $payload,
            criteriaResolver: function (array $row): array {
                $identifier = $this->stringValue($row['itemid'] ?? null);

                return [
                    ['property' => 'identificador_db', 'value' => $identifier],
                    ['property' => 'sku', 'value' => $identifier],
                ];
            }
        );
    }

    public function syncAccounts(array $payload = []): array
    {
        return $this->syncTable(
            table: 'custtable',
            hubspotObjectType: 'companies',
            defaultQuery: $this->buildModifiedDatetimeDefaultQuery('custtable', $payload),
            payload: $payload,
            criteriaResolver: function (array $row): array {
                return [
                    ['property' => 'identificador_db', 'value' => $this->stringValue($row['accountnum'] ?? null)],
                    ['property' => 'email', 'value' => $this->stringValue($row['Correo'] ?? null)],
                    ['property' => 'phone', 'value' => $this->stringValue($row['Telefono'] ?? null)],
                ];
            }
        );
    }

    public function syncContacts(array $payload = []): array
    {
        return $this->syncTable(
            table: 'contactos_cl',
            hubspotObjectType: 'contacts',
            defaultQuery: $this->buildModifiedDatetimeDefaultQuery('contactos_cl', $payload),
            payload: $payload,
            criteriaResolver: function (array $row): array {
                $locator = $this->stringValue($row['locator'] ?? null);
                $type = Str::lower($this->stringValue($row['Tipo'] ?? null) ?? '');

                $email = in_array($type, ['correo', 'email', 'mail'], true) ? $locator : null;
                $phone = in_array($type, ['telefono', 'teléfono', 'phone', 'celular'], true) ? $locator : null;

                return [
                    ['property' => 'identificador_db', 'value' => $this->stringValue($row['accountnum'] ?? null)],
                    ['property' => 'email', 'value' => $email],
                    ['property' => 'phone', 'value' => $phone],
                ];
            }
        );
    }

    public function updateCustomer(array $payload): array
    {
        return $this->updateAzureSqlTable('custtable', $payload);
    }

    public function updateContact(array $payload): array
    {
        return $this->updateAzureSqlTable('contactos_cl', $payload);
    }

    private function updateAzureSqlTable(string $table, array $payload): array
    {
        $items = array_is_list($payload) ? $payload : [$payload];
        $updated = 0;
        $warnings = [];
        $executed = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $warnings[] = [
                    'index' => $index,
                    'reason' => 'invalid_payload',
                ];

                continue;
            }

            $mappedPayload = $this->buildAzureSqlWritePayload($table, $item);
            $keyColumns = $this->resolveAzureSqlKeyColumns($table, $mappedPayload);
            if ($keyColumns === []) {
                $warnings[] = [
                    'index' => $index,
                    'reason' => 'missing_key_configuration',
                ];

                continue;
            }

            $missingKeys = array_values(array_filter(
                $keyColumns,
                fn (string $column): bool => ! array_key_exists($column, $mappedPayload) || $mappedPayload[$column] === null || $mappedPayload[$column] === ''
            ));

            if ($missingKeys !== []) {
                $warnings[] = [
                    'index' => $index,
                    'reason' => 'missing_key_columns',
                    'key_columns' => $keyColumns,
                    'missing' => $missingKeys,
                ];

                continue;
            }

            $updates = $this->filterWritableUpdateColumns($table, $mappedPayload, $keyColumns);
            if ($updates === []) {
                $warnings[] = [
                    'index' => $index,
                    'reason' => 'no_writable_columns',
                    'key_columns' => $keyColumns,
                ];

                continue;
            }

            $statement = $this->buildUpdateStatement($table, array_keys($updates), $keyColumns);
            $bindings = array_merge(
                array_values($updates),
                array_map(fn (string $column): mixed => $mappedPayload[$column], $keyColumns)
            );

            try {
                $affected = $this->makeConnection()->update($statement, $bindings);
            } catch (\Throwable $exception) {
                $warnings[] = [
                    'index' => $index,
                    'reason' => 'azure_sql_update_failed',
                    'query' => $statement,
                    'bindings_count' => count($bindings),
                    'error' => [
                        'exception' => get_class($exception),
                        'message' => $exception->getMessage(),
                    ],
                ];

                continue;
            }

            $updated += (int) $affected;
            $executed[] = [
                'index' => $index,
                'affected_rows' => (int) $affected,
                'updated_columns' => array_keys($updates),
                'key_columns' => $keyColumns,
            ];
        }

        $details = [
            'operation' => 'azure_sql_update',
            'table' => $table,
            'items_received' => count($items),
            'updated_count' => $updated,
            'warning_count' => count($warnings),
            'warnings' => $warnings,
            'executed' => $executed,
            'connection' => $this->sanitizedConnectionDetails(),
            'output_payload' => [],
        ];

        $this->mergeRecordDetails($details);

        if ($updated === 0) {
            return [
                'success' => true,
                'status' => 'warning',
                'message' => sprintf('Azure SQL update finished for %s without affected rows.', $table),
                'data' => $details,
            ];
        }

        return [
            'success' => true,
            'status' => $warnings === [] ? null : 'warning',
            'message' => $warnings === []
                ? sprintf('Azure SQL update finished for %s.', $table)
                : sprintf('Azure SQL update finished for %s with warnings.', $table),
            'data' => $details,
        ];
    }

    private function syncTable(string $table, string $hubspotObjectType, string $defaultQuery, array $payload, callable $criteriaResolver): array
    {
        if ($this->shouldPrepareBatchOutput($hubspotObjectType)) {
            return $this->prepareBatchOutput($table, $hubspotObjectType, $defaultQuery, $payload);
        }

        $query = trim((string) ($payload['command_sql'] ?? $this->event?->command_sql ?? $defaultQuery));
        $rowsRead = 0;
        $matchedRows = 0;
        $updatedRows = 0;
        $warningRows = 0;
        $updatedIds = [];
        $warnings = [];
        $outputPayload = [];
        $fallbackRows = 0;
        $nextEventConfigured = $this->event?->to_event_id !== null;

        try {
            $rows = $this->fetchRows($query, $table);
            $rowsRead = count($rows);
        } catch (\Throwable $exception) {
            return $this->failureResult(
                'Azure SQL query failed.',
                $table,
                $query,
                $exception
            );
        }

        foreach ($rows as $index => $row) {
            $preparedRow = $this->buildHubspotPayload($table, $row);

            $match = $this->findHubspotMatch($hubspotObjectType, $criteriaResolver($row));

            if (! ($match['success'] ?? false)) {
                if (($match['message'] ?? null) === 'hubspot_match_not_found' && $nextEventConfigured && ! empty($preparedRow)) {
                    $fallbackRows++;
                    $outputPayload[] = $preparedRow;

                    continue;
                }

                $warningRows++;
                $warnings[] = [
                    'row' => $index,
                    'reason' => $match['message'] ?? 'match_not_found',
                    'criteria' => $match['criteria_attempted'] ?? [],
                    'error' => $match['error'] ?? null,
                    'search_failures' => $match['search_failures'] ?? [],
                ];
                continue;
            }

            $matchedRows++;

            $properties = $preparedRow;
            if (empty($properties)) {
                $warningRows++;
                $warnings[] = [
                    'row' => $index,
                    'reason' => 'no_updatable_properties',
                    'hubspot_id' => $match['hubspot_id'] ?? null,
                ];
                continue;
            }

            $response = $this->hubspotApi->updateObject(
                $hubspotObjectType,
                (string) $match['hubspot_id'],
                $properties
            );

            if (! ($response['success'] ?? false)) {
                $warningRows++;
                $warnings[] = [
                    'row' => $index,
                    'reason' => 'hubspot_update_failed',
                    'hubspot_id' => $match['hubspot_id'] ?? null,
                    'criteria_used' => $match['matched_by'] ?? null,
                    'error' => $response['error'] ?? null,
                ];
                continue;
            }

            $updatedRows++;
            $updatedIds[] = (string) $match['hubspot_id'];
        }

        $modifiedFilterColumn = $this->modifiedFilterColumn($table);

        $details = [
            'table' => $table,
            'query' => $query,
            'sync_window_hours' => $modifiedFilterColumn ? $this->resolveSyncWindowHours($payload) : null,
            'modified_filter_column' => $modifiedFilterColumn,
            'rows_read' => $rowsRead,
            'rows_matched' => $matchedRows,
            'rows_updated' => $updatedRows,
            'rows_warning' => $warningRows,
            'rows_prepared_for_fallback' => $fallbackRows,
            'hubspot_updated_ids' => $updatedIds,
            'warnings' => $warnings,
            'connection' => $this->sanitizedConnectionDetails(),
            'output_payload' => $outputPayload,
        ];

        if ($details['sync_window_hours'] === null) {
            unset($details['sync_window_hours'], $details['modified_filter_column']);
        }

        $this->mergeRecordDetails($details);

        if ($rowsRead === 0) {
            return [
                'success' => true,
                'status' => 'warning',
                'message' => sprintf('Azure SQL sync finished for %s with no rows returned.', $table),
                'data' => $details,
            ];
        }

        if ($fallbackRows > 0) {
            return [
                'success' => true,
                'message' => $warningRows > 0
                    ? sprintf('Azure SQL sync prepared %d %s rows for HubSpot creation fallback with warnings.', $fallbackRows, $table)
                    : sprintf('Azure SQL sync prepared %d %s rows for HubSpot creation fallback.', $fallbackRows, $table),
                'data' => $details,
            ];
        }

        if ($updatedRows === 0) {
            return [
                'success' => true,
                'status' => 'warning',
                'message' => sprintf('Azure SQL sync finished for %s without HubSpot updates.', $table),
                'data' => $details,
            ];
        }

        return [
            'success' => true,
            'message' => sprintf('Azure SQL sync finished for %s.', $table),
            'data' => $details,
        ];
    }

    private function prepareBatchOutput(string $table, string $hubspotObjectType, string $defaultQuery, array $payload): array
    {
        $query = trim((string) ($payload['command_sql'] ?? $this->event?->command_sql ?? $defaultQuery));

        try {
            $rows = $this->fetchRows($query, $table);
        } catch (\Throwable $exception) {
            return $this->failureResult(
                'Azure SQL query failed.',
                $table,
                $query,
                $exception
            );
        }

        $outputPayload = [];
        $warningRows = [];

        foreach ($rows as $index => $row) {
            $mappedRow = $this->buildHubspotPayload($table, $row);

            if (empty($mappedRow)) {
                $warningRows[] = [
                    'row' => $index,
                    'reason' => 'no_updatable_properties',
                ];
                continue;
            }

            if (! $this->isValidOutputPayload($hubspotObjectType, $mappedRow)) {
                $warningRows[] = [
                    'row' => $index,
                    'reason' => 'invalid_output_payload',
                ];
                continue;
            }

            $outputPayload[] = $mappedRow;
        }

        $modifiedFilterColumn = $this->modifiedFilterColumn($table);

        $details = [
            'table' => $table,
            'query' => $query,
            'sync_window_hours' => $modifiedFilterColumn ? $this->resolveSyncWindowHours($payload) : null,
            'modified_filter_column' => $modifiedFilterColumn,
            'rows_read' => count($rows),
            'rows_prepared' => count($outputPayload),
            'rows_warning' => count($warningRows),
            'warnings' => $warningRows,
            'connection' => $this->sanitizedConnectionDetails(),
            'dispatch_mode' => 'next_event',
            'next_event_id' => $this->event?->to_event_id,
            'output_payload_count' => count($outputPayload),
            'output_payload_sample' => array_slice($outputPayload, 0, 3),
            'hubspot_runtime_platform' => $this->resolveHubspotRuntimePlatform()?->only(['id', 'name', 'slug', 'type']),
        ];

        if ($details['sync_window_hours'] === null) {
            unset($details['sync_window_hours'], $details['modified_filter_column']);
        }

        $this->mergeRecordDetails($details);

        if (count($outputPayload) === 0) {
            return [
                'success' => true,
                'status' => 'warning',
                'message' => sprintf('Azure SQL sync finished for %s without records prepared for the next event.', $table),
                'data' => $details + ['output_payload' => []],
            ];
        }

        return [
            'success' => true,
            'message' => sprintf('Azure SQL sync prepared %d records for %s.', count($outputPayload), $table),
            'data' => $details + ['output_payload' => $outputPayload],
        ];
    }

    private function buildModifiedDatetimeDefaultQuery(string $table, array $payload): string
    {
        $windowHours = $this->resolveSyncWindowHours($payload);
        $modifiedFilterColumn = $this->modifiedFilterColumn($table);

        return sprintf(
            'SELECT * FROM [dbo].[%s] WHERE [%s] >= DATEADD(MINUTE, -%d, GETDATE()) ORDER BY [%s] ASC',
            $table,
            $modifiedFilterColumn,
            $windowHours,
            $modifiedFilterColumn
        );
    }

    private function modifiedFilterColumn(string $table): ?string
    {
        return self::MODIFIED_FILTER_COLUMNS[$table] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRows(string $query, string $table): array
    {
        $connection = $this->makeConnection();
        $rows = $connection->select($query);

        return array_map(function (object|array $row) use ($table): array {
            $values = (array) $row;
            $normalized = [];

            foreach ($values as $key => $value) {
                $normalized[$key] = $this->normalizeValue($table, (string) $key, $value);
            }

            return $normalized;
        }, $rows);
    }

    private function makeConnection(): ConnectionInterface
    {
        config([
            'database.connections.' . self::RUNTIME_CONNECTION => $this->buildConnectionConfig(),
        ]);

        DB::purge(self::RUNTIME_CONNECTION);

        return DB::connection(self::RUNTIME_CONNECTION);
    }

    private function buildConnectionConfig(): array
    {
        $credentials = is_array($this->platform->credentials) ? $this->platform->credentials : [];
        $settings = is_array($this->platform->settings) ? $this->platform->settings : [];

        return [
            'driver' => 'sqlsrv',
            'host' => $settings['host'] ?? $credentials['host'] ?? 'localhost',
            'port' => (string) ($settings['port'] ?? $credentials['port'] ?? '1433'),
            'database' => $settings['database'] ?? $credentials['database'] ?? '',
            'username' => $credentials['username'] ?? '',
            'password' => $credentials['password'] ?? '',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => (bool) ($settings['encrypt'] ?? true),
            'trust_server_certificate' => (bool) ($settings['trust_server_certificate'] ?? false),
            'options' => array_filter([
                'LoginTimeout' => (int) ($settings['login_timeout'] ?? 30),
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * @param  list<array{property:string, value:mixed}>  $criteria
     * @return array<string, mixed>
     */
    private function findHubspotMatch(string $objectType, array $criteria): array
    {
        $attempted = [];
        $searchFailures = [];
        $successfulSearches = 0;

        foreach ($criteria as $criterion) {
            $property = (string) ($criterion['property'] ?? '');
            $value = $criterion['value'] ?? null;

            if ($property === '' || $value === null || $value === '') {
                continue;
            }

            $attempted[] = [
                'property' => $property,
                'value' => is_scalar($value) ? (string) $value : '[non_scalar]',
            ];

            $response = $this->hubspotApi->searchObjectByProperty($objectType, $property, $value, [$property]);

            if (! ($response['success'] ?? false)) {
                $searchFailures[] = [
                    'property' => $property,
                    'value' => is_scalar($value) ? (string) $value : '[non_scalar]',
                    'status_code' => $response['status_code'] ?? null,
                    'error' => $response['error'] ?? null,
                ];

                continue;
            }

            $successfulSearches++;
            $results = Arr::get($response, 'data.results', []);
            if (! is_array($results) || count($results) === 0) {
                continue;
            }

            if (count($results) > 1) {
                return [
                    'success' => false,
                    'message' => 'multiple_hubspot_matches',
                    'criteria_attempted' => $attempted,
                ];
            }

            return [
                'success' => true,
                'hubspot_id' => (string) Arr::get($results, '0.id'),
                'matched_by' => $property,
                'criteria_attempted' => $attempted,
                'search_failures' => $searchFailures,
            ];
        }

        if ($searchFailures !== [] && $successfulSearches === 0) {
            return [
                'success' => false,
                'message' => 'hubspot_search_failed',
                'criteria_attempted' => $attempted,
                'status_code' => $searchFailures[0]['status_code'] ?? null,
                'error' => $searchFailures[0]['error'] ?? null,
                'search_failures' => $searchFailures,
            ];
        }

        return [
            'success' => false,
            'message' => 'hubspot_match_not_found',
            'criteria_attempted' => $attempted,
            'search_failures' => $searchFailures,
        ];
    }

    private function buildHubspotPayload(string $table, array $row): array
    {
        $allowedColumns = self::UPSERTABLE_COLUMNS[$table] ?? [];
        $payload = [];

        if ($this->event) {
            $this->event->loadMissing([
                'propertyRelationships.property:id,key,name',
                'propertyRelationships.relatedProperty:id,key,name',
            ]);
        }

        $relationships = $this->event?->propertyRelationships ?? collect();
        foreach ($relationships as $relationship) {
            if (! $relationship instanceof PropertyRelationship || ! $relationship->active) {
                continue;
            }

            $sourceKey = $relationship->mapping_key ?: $relationship->property?->key;
            $targetKey = $relationship->relatedProperty?->key;

            if (! is_string($sourceKey) || trim($sourceKey) === '' || ! is_string($targetKey) || trim($targetKey) === '') {
                continue;
            }

            if (! in_array($sourceKey, $allowedColumns, true)) {
                continue;
            }

            $value = $row[$sourceKey] ?? null;
            if ($value === null) {
                continue;
            }

            $payload[$targetKey] = $value;
        }

        if (! empty($payload)) {
            return $payload;
        }

        $fallbackMapping = is_array($this->event?->payload_mapping) ? $this->event?->payload_mapping : [];
        foreach ($fallbackMapping as $sourceKey => $targetKey) {
            if (! is_string($sourceKey) || ! is_string($targetKey)) {
                continue;
            }

            if (! in_array($sourceKey, $allowedColumns, true)) {
                continue;
            }

            $value = $row[$sourceKey] ?? null;
            if ($value === null) {
                continue;
            }

            $payload[$targetKey] = $value;
        }

        return $payload;
    }

    private function buildAzureSqlWritePayload(string $table, array $payload): array
    {
        $allowedColumns = array_values(array_unique(array_merge(
            self::WRITABLE_COLUMNS[$table] ?? [],
            self::DEFAULT_KEY_COLUMNS[$table] ?? []
        )));
        $mappedPayload = [];

        if ($this->event) {
            $this->event->loadMissing([
                'propertyRelationships.property:id,key,name',
                'propertyRelationships.relatedProperty:id,key,name',
            ]);
        }

        $relationships = $this->event?->propertyRelationships ?? collect();
        foreach ($relationships as $relationship) {
            if (! $relationship instanceof PropertyRelationship || ! $relationship->active) {
                continue;
            }

            $sourceKey = $relationship->mapping_key ?: $relationship->property?->key;
            $targetKey = $relationship->relatedProperty?->key;

            if (! is_string($sourceKey) || trim($sourceKey) === '' || ! is_string($targetKey) || trim($targetKey) === '') {
                continue;
            }

            if (! in_array($targetKey, $allowedColumns, true) || ! array_key_exists($sourceKey, $payload)) {
                continue;
            }

            $mappedPayload[$targetKey] = $this->normalizeValue($table, $targetKey, $payload[$sourceKey]);
        }

        foreach ($payload as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedColumns, true) || array_key_exists($key, $mappedPayload)) {
                continue;
            }

            $mappedPayload[$key] = $this->normalizeValue($table, $key, $value);
        }

        $fallbackMapping = is_array($this->event?->payload_mapping) ? $this->event?->payload_mapping : [];
        foreach ($fallbackMapping as $sourceKey => $targetKey) {
            if (! is_string($sourceKey) || ! is_string($targetKey)) {
                continue;
            }

            if (! in_array($targetKey, $allowedColumns, true) || ! array_key_exists($sourceKey, $payload) || array_key_exists($targetKey, $mappedPayload)) {
                continue;
            }

            $mappedPayload[$targetKey] = $this->normalizeValue($table, $targetKey, $payload[$sourceKey]);
        }

        return $mappedPayload;
    }

    /**
     * @return list<string>
     */
    private function resolveAzureSqlKeyColumns(string $table, array $payload): array
    {
        $meta = is_array($this->event?->meta) ? $this->event->meta : [];
        $configured = Arr::get($meta, 'azure_sql.key_columns', Arr::get($meta, 'key_columns'));
        $columns = array_values(array_filter(Arr::wrap($configured), 'is_string'));

        if ($columns === []) {
            $columns = self::DEFAULT_KEY_COLUMNS[$table] ?? [];
        }

        $allowedColumns = array_values(array_unique(array_merge(
            self::WRITABLE_COLUMNS[$table] ?? [],
            self::DEFAULT_KEY_COLUMNS[$table] ?? []
        )));

        return array_values(array_filter(
            $columns,
            fn (string $column): bool => in_array($column, $allowedColumns, true)
        ));
    }

    private function filterWritableUpdateColumns(string $table, array $payload, array $keyColumns): array
    {
        $updates = [];

        foreach (self::WRITABLE_COLUMNS[$table] ?? [] as $column) {
            if (in_array($column, $keyColumns, true) || ! array_key_exists($column, $payload)) {
                continue;
            }

            $updates[$column] = $payload[$column];
        }

        return $updates;
    }

    private function buildUpdateStatement(string $table, array $updateColumns, array $keyColumns): string
    {
        $setClause = implode(', ', array_map(
            fn (string $column): string => sprintf('[%s] = ?', str_replace(']', ']]', $column)),
            $updateColumns
        ));
        $whereClause = implode(' AND ', array_map(
            fn (string $column): string => sprintf('[%s] = ?', str_replace(']', ']]', $column)),
            $keyColumns
        ));

        return sprintf(
            'UPDATE [dbo].[%s] SET %s WHERE %s',
            str_replace(']', ']]', $table),
            $setClause,
            $whereClause
        );
    }

    private function normalizeValue(string $table, string $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
        }

        if (in_array($column, self::NUMERIC_COLUMNS[$table] ?? [], true)) {
            return is_numeric($value) ? (float) $value : $value;
        }

        return $value;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function resolveSyncWindowHours(array $payload, int $default = 24): int
    {
        $meta = is_array($this->event?->meta) ? $this->event->meta : [];
        $candidate = $payload['sync_window_hours'] ?? $meta['sync_window_hours'] ?? $default;

        if (is_numeric($candidate)) {
            $hours = (int) $candidate;

            if ($hours > 0) {
                return $hours;
            }
        }

        return $default;
    }

    private function isValidOutputPayload(string $hubspotObjectType, array $payload): bool
    {
        if ($hubspotObjectType !== 'products') {
            return $payload !== [];
        }

        return $this->isValidProductOutputPayload($payload);
    }

    private function isValidProductOutputPayload(array $payload): bool
    {
        $identifier = trim((string) ($payload['identificador_db'] ?? ''));
        $sku = trim((string) ($payload['sku'] ?? ''));

        return $identifier !== '' || $sku !== '';
    }

    private function failureResult(string $message, string $table, string $query, \Throwable $exception): array
    {
        $details = [
            'table' => $table,
            'query' => $query,
            'connection' => $this->sanitizedConnectionDetails(),
            'hubspot_runtime_platform' => $this->resolveHubspotRuntimePlatform()?->only(['id', 'name', 'slug', 'type']),
            'error' => [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ],
        ];

        $this->mergeRecordDetails($details);

        return [
            'success' => false,
            'message' => $message,
            'data' => $details,
        ];
    }

    private function sanitizedConnectionDetails(): array
    {
        $settings = is_array($this->platform->settings) ? $this->platform->settings : [];
        $credentials = is_array($this->platform->credentials) ? $this->platform->credentials : [];

        return array_filter([
            'driver' => $settings['service_driver'] ?? $credentials['service_driver'] ?? null,
            'host' => $settings['host'] ?? $credentials['host'] ?? null,
            'port' => $settings['port'] ?? $credentials['port'] ?? null,
            'database' => $settings['database'] ?? $credentials['database'] ?? null,
            'username' => $credentials['username'] ?? null,
            'encrypt' => $settings['encrypt'] ?? null,
            'trust_server_certificate' => $settings['trust_server_certificate'] ?? null,
            'login_timeout' => $settings['login_timeout'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function mergeRecordDetails(array $details): void
    {
        if (! $this->record) {
            return;
        }

        $existing = is_array($this->record->details) ? $this->record->details : [];

        $this->record->update([
            'details' => array_replace_recursive($existing, $details),
        ]);
    }

    private function shouldPrepareBatchOutput(string $hubspotObjectType): bool
    {
        $nextEvent = $this->event?->to_event;

        if (! $nextEvent) {
            return false;
        }

        if (($nextEvent->platform?->type ?? null) !== 'hubspot') {
            return false;
        }

        return match ($hubspotObjectType) {
            'products' => $nextEvent->event_type_id === 'product.updated',
            'companies' => $nextEvent->event_type_id === 'company.updated',
            'contacts' => $nextEvent->event_type_id === 'contact.updated',
            default => false,
        };
    }

    private function applyHubspotRuntimeConfig(): void
    {
        $hubspotPlatform = $this->resolveHubspotRuntimePlatform();
        if (! $hubspotPlatform) {
            return;
        }

        $credentials = is_array($hubspotPlatform->credentials) ? $hubspotPlatform->credentials : [];
        $settings = is_array($hubspotPlatform->settings) ? $hubspotPlatform->settings : [];
        $overrides = [];

        $token = $credentials['access_token'] ?? $credentials['api_token'] ?? null;
        if (is_string($token) && trim($token) !== '') {
            $overrides['hubspot.access_token'] = $token;
        }

        $baseUrl = $settings['base_url'] ?? null;
        if (is_string($baseUrl) && trim($baseUrl) !== '') {
            $overrides['hubspot.base_url'] = $baseUrl;
        }

        $timeout = $settings['timeout_seconds'] ?? null;
        if (is_numeric($timeout)) {
            $overrides['hubspot.timeout_seconds'] = (int) $timeout;
        }

        if (! empty($overrides)) {
            config($overrides);
        }
    }

    private function resolveHubspotRuntimePlatform(): ?Platform
    {
        if (($this->platform->type ?? null) === 'hubspot') {
            return $this->platform;
        }

        $nextPlatform = $this->event?->to_event?->platform;
        if ($nextPlatform instanceof Platform && $nextPlatform->type === 'hubspot') {
            return $nextPlatform;
        }

        return null;
    }
}

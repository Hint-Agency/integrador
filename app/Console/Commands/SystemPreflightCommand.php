<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemPreflightCommand extends Command
{
    protected $signature = 'system:preflight
        {--strict : Return non-zero when warnings are found}
        {--skip-env : Skip required environment/config validation}
        {--skip-seed : Skip baseline roles/superadmin validation}';

    protected $description = 'Run pre-production checks for Integrador';

    /** @var array<int, array{status: string, check: string, details: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->line('Running preflight checks...');

        $dbReady = $this->checkDatabase();
        if ($dbReady) {
            $this->checkRequiredTables();
        }

        $this->checkQueueDriver();
        $this->checkCoreCommands();
        $this->checkWritablePaths();

        if (! $this->option('skip-env')) {
            $this->checkRequiredEnvironment();
        } else {
            $this->warnResult('Required environment variables', 'Skipped by --skip-env option.');
        }

        if (! $this->option('skip-seed') && $dbReady) {
            $this->checkBaselineAccessData();
        } elseif ($this->option('skip-seed')) {
            $this->warnResult('Baseline access data', 'Skipped by --skip-seed option.');
        } else {
            $this->warnResult('Baseline access data', 'Skipped because database check failed.');
        }

        $this->renderResults();

        $failed = count(array_filter($this->results, fn (array $row): bool => $row['status'] === 'FAIL'));
        $warnings = count(array_filter($this->results, fn (array $row): bool => $row['status'] === 'WARN'));

        if ($failed > 0) {
            $this->error("Preflight failed: {$failed} check(s) with FAIL status.");

            return Command::FAILURE;
        }

        if ($this->option('strict') && $warnings > 0) {
            $this->error("Preflight strict mode failed: {$warnings} warning(s) detected.");

            return Command::FAILURE;
        }

        $this->info('Preflight completed successfully.');

        return Command::SUCCESS;
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();
            $this->passResult('Database connection', 'Connection established.');

            return true;
        } catch (\Throwable $exception) {
            $this->failResult('Database connection', $exception->getMessage());

            return false;
        }
    }

    private function checkRequiredTables(): void
    {
        $requiredTables = [
            'platforms',
            'events',
            'records',
            'properties',
            'roles',
            'permissions',
            'users',
            'webhook_calls',
            'event_http_configs',
            'event_idempotency_keys',
        ];

        $missing = [];
        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        if (empty($missing)) {
            $this->passResult('Required tables', 'All required tables exist.');

            return;
        }

        $this->failResult('Required tables', 'Missing: '.implode(', ', $missing));
    }

    private function checkQueueDriver(): void
    {
        $queueDriver = (string) config('queue.default', 'sync');
        if ($queueDriver === 'sync') {
            $this->warnResult('Queue driver', 'Current driver is sync; use async driver in production.');

            return;
        }

        $this->passResult('Queue driver', "Driver: {$queueDriver}");
    }

    private function checkCoreCommands(): void
    {
        $expected = [
            'events:search-schedule',
            'events:clear-cache',
            'events:clear-records',
            'events:clear-all',
            'hubspot:regenerate-cache',
            'products:cache',
        ];

        /** @var Kernel $kernel */
        $kernel = app(Kernel::class);
        $available = array_keys($kernel->all());

        $missing = array_values(array_diff($expected, $available));
        if (empty($missing)) {
            $this->passResult('Core artisan commands', 'All required commands are registered.');

            return;
        }

        $this->failResult('Core artisan commands', 'Missing: '.implode(', ', $missing));
    }

    private function checkWritablePaths(): void
    {
        $paths = [
            'storage' => storage_path(),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        $issues = [];
        foreach ($paths as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $issues[] = "{$label} not writable";
            }
        }

        if (empty($issues)) {
            $this->passResult('Writable paths', 'storage and bootstrap/cache are writable.');

            return;
        }

        $this->failResult('Writable paths', implode('; ', $issues));
    }

    private function checkRequiredEnvironment(): void
    {
        $defaultConnection = (string) config('database.default');
        $defaultDbConfig = config('database.connections.'.$defaultConnection, []);

        $checks = [
            'DB_CONNECTION' => $defaultConnection,
            'DB_DATABASE' => $defaultDbConfig['database'] ?? null,
            'HUBSPOT_ACCESS_TOKEN' => config('hubspot.access_token'),
            'NETSUITE_ACCOUNT' => config('netsuite.account'),
            'NETSUITE_CONSUMER_KEY' => config('netsuite.consumer_key'),
            'NETSUITE_CONSUMER_SECRET' => config('netsuite.consumer_secret'),
            'NETSUITE_TOKEN_ID' => config('netsuite.token_id'),
            'NETSUITE_TOKEN_SECRET' => config('netsuite.token_secret'),
            'NETSUITE_PRIVATE_KEY' => config('netsuite.private_key'),
        ];

        if ($defaultConnection !== 'sqlite') {
            $checks += [
                'DB_HOST' => $defaultDbConfig['host'] ?? null,
                'DB_PORT' => $defaultDbConfig['port'] ?? null,
                'DB_USERNAME' => $defaultDbConfig['username'] ?? null,
            ];
        }

        $missing = [];
        foreach ($checks as $key => $value) {
            if ($value === null) {
                $missing[] = $key;

                continue;
            }

            if (is_string($value) && trim($value) === '') {
                $missing[] = $key;
            }
        }

        if (empty($missing)) {
            $details = 'All required values are configured.';
            if ($this->hasPlatformConfiguredOdoo()) {
                $details .= ' Odoo may be configured from platforms.credentials/settings.';
            }

            $this->passResult('Required environment/config values', $details);

            return;
        }

        $this->failResult('Required environment/config values', 'Missing: '.implode(', ', $missing));
    }

    private function hasPlatformConfiguredOdoo(): bool
    {
        if (! Schema::hasTable('platforms')) {
            return false;
        }

        return Platform::query()
            ->where('type', 'odoo')
            ->where(function ($query): void {
                $query->whereNotNull('credentials')
                    ->orWhereNotNull('settings');
            })
            ->exists();
    }

    private function checkBaselineAccessData(): void
    {
        $requiredRoles = ['superadmin', 'admin'];
        $foundRoles = Role::query()
            ->whereIn('slug', $requiredRoles)
            ->pluck('slug')
            ->all();

        $missingRoles = array_values(array_diff($requiredRoles, $foundRoles));
        if (! empty($missingRoles)) {
            $this->failResult('Baseline roles', 'Missing roles: '.implode(', ', $missingRoles));

            return;
        }

        $this->passResult('Baseline roles', 'admin and superadmin roles exist.');

        $requiredPermissions = [
            'dashboard.view',
            'events.view',
            'events.manage',
            'records.view',
            'platforms.manage',
            'properties.manage',
            'configs.manage',
            'categories.manage',
            'roles.manage',
            'users.manage',
        ];

        $foundPermissions = Permission::query()
            ->whereIn('slug', $requiredPermissions)
            ->pluck('slug')
            ->all();

        $missingPermissions = array_values(array_diff($requiredPermissions, $foundPermissions));
        if (! empty($missingPermissions)) {
            $this->failResult('Baseline permissions', 'Missing permissions: '.implode(', ', $missingPermissions));

            return;
        }

        $this->passResult('Baseline permissions', 'Core admin permissions are present.');

        $superAdminEmail = env('SUPERADMIN_EMAIL', 'carlos91rubio@gmail.com');
        $superAdmin = User::query()->where('email', $superAdminEmail)->first();
        if (! $superAdmin) {
            $this->failResult('Bootstrap superadmin', 'User '.$superAdminEmail.' not found.');

            return;
        }

        if (! $superAdmin->hasRole('superadmin')) {
            $this->failResult('Bootstrap superadmin', 'User exists but does not have superadmin role.');

            return;
        }

        $this->passResult('Bootstrap superadmin', 'Superadmin user exists and role assignment is valid.');
    }

    private function renderResults(): void
    {
        $rows = array_map(function (array $result): array {
            return [
                $result['status'],
                $result['check'],
                $result['details'],
            ];
        }, $this->results);

        $this->newLine();
        $this->table(['Status', 'Check', 'Details'], $rows);
    }

    private function passResult(string $check, string $details): void
    {
        $this->results[] = [
            'status' => 'PASS',
            'check' => $check,
            'details' => $details,
        ];
    }

    private function warnResult(string $check, string $details): void
    {
        $this->results[] = [
            'status' => 'WARN',
            'check' => $check,
            'details' => $details,
        ];
    }

    private function failResult(string $check, string $details): void
    {
        $this->results[] = [
            'status' => 'FAIL',
            'check' => $check,
            'details' => $details,
        ];
    }
}

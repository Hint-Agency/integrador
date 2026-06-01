<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissionSlugs = [
            'dashboard.view',
            'events.view',
            'events.manage',
            'records.view',
            'records.manage',
            'platforms.manage',
            'properties.manage',
            'configs.manage',
            'categories.manage',
            'roles.manage',
            'users.manage',
        ];

        $permissionIds = collect($permissionSlugs)
            ->map(function (string $slug): int {
                $permission = Permission::query()->firstOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => ucwords(str_replace(['.', '_'], ' ', $slug)),
                        'description' => 'Auto-generated permission for integrador admin modules.',
                    ]
                );

                return $permission->id;
            })
            ->all();

        $adminRole = Role::query()->firstOrCreate(
            ['slug' => 'admin'],
            [
                'name' => 'Admin',
                'description' => 'High privilege role with assigned permissions',
            ]
        );

        $superAdminRole = Role::query()->firstOrCreate(
            ['slug' => 'superadmin'],
            [
                'name' => 'Super Admin',
                'description' => 'Top-level role with global bypass permissions',
            ]
        );

        $operatorRole = Role::query()->firstOrCreate(
            ['slug' => 'operator'],
            [
                'name' => 'Operator',
                'description' => 'Operational role with read and execute access',
            ]
        );

        $viewerRole = Role::query()->firstOrCreate(
            ['slug' => 'viewer'],
            [
                'name' => 'Viewer',
                'description' => 'Read-only role for dashboards and traceability',
            ]
        );

        $adminRole->permissions()->syncWithoutDetaching($permissionIds);
        $superAdminRole->permissions()->syncWithoutDetaching($permissionIds);

        $operatorPermissionIds = Permission::query()
            ->whereIn('slug', ['dashboard.view', 'events.view', 'events.manage', 'records.view'])
            ->pluck('id')
            ->all();

        $operatorRole->permissions()->sync($operatorPermissionIds);

        $viewerPermissionIds = Permission::query()
            ->whereIn('slug', ['dashboard.view', 'events.view', 'records.view'])
            ->pluck('id')
            ->all();

        $viewerRole->permissions()->sync($viewerPermissionIds);
    }
}

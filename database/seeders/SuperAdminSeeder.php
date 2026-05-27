<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = User::query()->firstOrCreate(
            ['email' => env('SUPERADMIN_EMAIL', 'carlos91rubio@gmail.com')],
            [
                'username' => env('SUPERADMIN_USERNAME', 'superadmin'),
                'first_name' => env('SUPERADMIN_FIRST_NAME', 'Super'),
                'last_name' => env('SUPERADMIN_LAST_NAME', 'Admin'),
                'name' => env('SUPERADMIN_NAME', 'Super Admin'),
                'password' => Hash::make(env('SUPERADMIN_PASSWORD', Str::random(40))),
            ],
        );

        $role = Role::query()->firstOrCreate(
            ['slug' => 'superadmin'],
            [
                'name' => 'Super Admin',
                'description' => 'Top-level role with global bypass permissions',
            ]
        );

        $superAdmin->roles()->syncWithoutDetaching([$role->id]);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(SuperAdminSeeder::class);
        $this->call(AzureSqlProductPropertiesSeeder::class);
        $this->call(AspelWarehouseMappingsSeeder::class);
        $this->call(AspelQuoteFlowSeeder::class);

        $admin = User::query()->firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.com')],
            [
                'username' => 'admin',
                'first_name' => 'Admin',
                'last_name' => 'User',
                'name' => 'Admin User',
                'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
            ]
        );

        $adminRole = Role::query()->where('slug', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->syncWithoutDetaching([$adminRole->id]);
        }

        User::query()->firstOrCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ], [
            'username' => 'testuser',
            'first_name' => 'Test',
            'last_name' => 'User',
            'password' => Hash::make('password'),
        ]);
    }
}

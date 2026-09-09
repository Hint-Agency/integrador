<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_assignment_rules', function (Blueprint $table): void {
            $table->json('owner_rotation_state')
                ->nullable()
                ->after('last_assigned_owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('owner_assignment_rules', function (Blueprint $table): void {
            $table->dropColumn('owner_rotation_state');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_assignment_rules', function (Blueprint $table): void {
            $table->foreignId('last_assigned_owner_id')
                ->nullable()
                ->after('owner_selection_strategy')
                ->constrained('hubspot_owners')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('owner_assignment_rules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('last_assigned_owner_id');
        });
    }
};

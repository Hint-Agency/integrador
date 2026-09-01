<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_assignment_rules', function (Blueprint $table): void {
            $table->string('existing_owner_behavior')
                ->default('stop')
                ->after('owner_selection_strategy');
        });
    }

    public function down(): void
    {
        Schema::table('owner_assignment_rules', function (Blueprint $table): void {
            $table->dropColumn('existing_owner_behavior');
        });
    }
};

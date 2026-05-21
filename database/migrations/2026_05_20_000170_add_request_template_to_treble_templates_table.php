<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treble_templates', function (Blueprint $table): void {
            $table->json('request_template')->nullable()->after('payload_mapping');
        });

        DB::table('treble_templates')
            ->whereNull('request_template')
            ->update([
                'request_template' => DB::raw('payload_mapping'),
            ]);
    }

    public function down(): void
    {
        Schema::table('treble_templates', function (Blueprint $table): void {
            $table->dropColumn('request_template');
        });
    }
};

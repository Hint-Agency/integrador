<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_assignment_rules', function (Blueprint $table): void {
            $table->boolean('continue_to_treble')->default(false)->after('owner_selection_strategy');
        });

        Schema::table('message_rules', function (Blueprint $table): void {
            $table->foreignId('automation_flow_id')
                ->nullable()
                ->after('client_id')
                ->constrained('owner_assignment_rules')
                ->nullOnDelete();
        });

        DB::table('owner_assignment_rules')
            ->where('send_treble', true)
            ->update(['continue_to_treble' => true]);

        DB::table('owner_assignment_rules')
            ->where('send_treble', true)
            ->orderBy('id')
            ->get()
            ->each(function (object $flow): void {
                $matchingRules = DB::table('message_rules')
                    ->where('client_id', $flow->client_id)
                    ->where('trigger_property', $flow->trigger_property)
                    ->where(function ($query) use ($flow): void {
                        if ($flow->trigger_value === null) {
                            $query->whereNull('trigger_value');

                            return;
                        }

                        $query->where('trigger_value', $flow->trigger_value);
                    });

                if ($matchingRules->exists()) {
                    $matchingRules->update(['automation_flow_id' => $flow->id]);

                    return;
                }

                if ($flow->treble_template_id === null) {
                    return;
                }

                DB::table('message_rules')->insert([
                    'client_id' => $flow->client_id,
                    'automation_flow_id' => $flow->id,
                    'treble_template_id' => $flow->treble_template_id,
                    'name' => $flow->name.' - Treble',
                    'priority' => $flow->priority,
                    'trigger_property' => $flow->trigger_property,
                    'trigger_value' => $flow->trigger_value,
                    'conditions' => json_encode(['match' => 'all', 'groups' => []]),
                    'active' => $flow->active,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('message_rules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('automation_flow_id');
        });

        Schema::table('owner_assignment_rules', function (Blueprint $table): void {
            $table->dropColumn('continue_to_treble');
        });
    }
};

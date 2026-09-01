<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hubspot_owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('external_owner_id');
            $table->string('email')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['client_id', 'external_owner_id']);
        });

        Schema::create('owner_assignment_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treble_template_id')->nullable()->constrained('treble_templates')->nullOnDelete();
            $table->string('name');
            $table->integer('priority')->default(100);
            $table->string('trigger_property');
            $table->string('trigger_value')->nullable();
            $table->json('conditions')->nullable();
            $table->string('owner_property')->default('hubspot_owner_id');
            $table->string('owner_selection_strategy')->default('random');
            $table->boolean('send_treble')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('hubspot_owner_owner_assignment_rule', function (Blueprint $table) {
            $table->foreignId('owner_assignment_rule_id');
            $table->foreignId('hubspot_owner_id');
            $table->primary(['owner_assignment_rule_id', 'hubspot_owner_id']);
            $table->foreign('owner_assignment_rule_id', 'owner_rule_owner_rule_fk')
                ->references('id')
                ->on('owner_assignment_rules')
                ->cascadeOnDelete();
            $table->foreign('hubspot_owner_id', 'owner_rule_owner_fk')
                ->references('id')
                ->on('hubspot_owners')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubspot_owner_owner_assignment_rule');
        Schema::dropIfExists('owner_assignment_rules');
        Schema::dropIfExists('hubspot_owners');
    }
};

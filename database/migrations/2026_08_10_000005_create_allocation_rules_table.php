<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_category_id')->constrained()->cascadeOnDelete();
            $table->string('rule_type'); // per_day|fixed|hybrid
            $table->json('configuration');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['expense_category_id', 'version'], 'allocation_rules_category_version_uq');
            $table->index(['expense_category_id', 'effective_from', 'effective_to'], 'allocation_rules_category_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allocation_rules');
    }
};

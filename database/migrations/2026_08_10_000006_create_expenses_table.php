<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('paid_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('amount', 15, 2);
            $table->date('expense_date');
            $table->date('period_start_date')->nullable();
            $table->date('period_end_date')->nullable();
            $table->string('status')->default('draft'); // draft|confirmed|cancelled
            $table->foreignId('allocation_rule_id')->nullable()->constrained('allocation_rules')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['house_id', 'status', 'expense_date'], 'expenses_house_status_date_idx');
            $table->index(['house_id', 'period_start_date', 'period_end_date'], 'expenses_house_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};

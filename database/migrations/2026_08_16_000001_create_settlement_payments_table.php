<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('status')->default('pending'); // pending|confirmed|rejected|cancelled
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['house_id', 'status'], 'settlement_payments_house_status_idx');
            $table->index(['house_id', 'to_user_id', 'status'], 'settlement_payments_to_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_payments');
    }
};

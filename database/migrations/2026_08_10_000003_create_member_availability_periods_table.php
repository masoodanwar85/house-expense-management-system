<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_availability_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable(); // null = still ongoing
            $table->string('status'); // available|unavailable
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['house_id', 'user_id', 'start_date', 'end_date'], 'map_house_user_dates_idx');
            $table->index(['house_id', 'status'], 'map_house_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_availability_periods');
    }
};

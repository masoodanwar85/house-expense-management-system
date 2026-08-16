<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_payments', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->default(2026)->after('to_user_id');
            $table->unsignedTinyInteger('month')->default(1)->after('year');
            $table->index(['house_id', 'year', 'month', 'status'], 'settlement_payments_house_ym_status_idx');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('UPDATE settlement_payments SET year = YEAR(created_at), month = MONTH(created_at)');
        } else {
            foreach (DB::table('settlement_payments')->get(['id', 'created_at']) as $row) {
                $timestamp = strtotime((string) $row->created_at);
                DB::table('settlement_payments')->where('id', $row->id)->update([
                    'year' => (int) date('Y', $timestamp),
                    'month' => (int) date('n', $timestamp),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('settlement_payments', function (Blueprint $table) {
            $table->dropIndex('settlement_payments_house_ym_status_idx');
            $table->dropColumn(['year', 'month']);
        });
    }
};

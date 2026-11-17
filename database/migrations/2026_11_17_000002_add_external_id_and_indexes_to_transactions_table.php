<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Внешний ID платежа (например, yookassa:<charge_id>). Unique не даёт
            // зачислить один платёж дважды, если Telegram повторит вебхук.
            $table->string('external_id')->nullable()->unique()->after('subject_id');

            // Баланс считается агрегатом по этим полям на каждом экране и в кроне
            $table->index(['user_id', 'is_active']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['subject_type', 'subject_id']);
            $table->dropIndex(['user_id', 'is_active']);
            $table->dropUnique(['external_id']);
            $table->dropColumn('external_id');
        });
    }
};

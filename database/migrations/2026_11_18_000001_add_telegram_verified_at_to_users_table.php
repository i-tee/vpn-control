<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Когда бот сам видел этого юзера в Telegram (по /start). Пусто у
 * импортированных: их telegram_id угадан по паролю канала и мог оказаться
 * неверным. Таких бот при /start привязывает по username к настоящему id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('telegram_verified_at')->nullable()->after('telegram_username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('telegram_verified_at');
        });
    }
};

<?php

// database/migrations/xxxx_xx_xx_xxxxxx_add_telegram_fields_to_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Добавляем поля для Telegram
            $table->unsignedBigInteger('telegram_id')->nullable()->unique()->after('id'); // Уникальный ID Telegram
            $table->string('telegram_first_name')->nullable()->after('name'); // Имя из Telegram
            $table->string('telegram_last_name')->nullable()->after('telegram_first_name'); // Фамилия из Telegram
            $table->string('telegram_username')->nullable()->unique()->after('telegram_last_name'); // Username из Telegram
            // Поле is_bot не добавляем, так как предполагаем, что в таблице пользователей у нас только люди
            // Если нужно, можно добавить, но логика будет проверять это при регистрации
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telegram_id', 'telegram_first_name', 'telegram_last_name', 'telegram_username']);
        });
    }
};
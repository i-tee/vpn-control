<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * На старом проде колонку clients.is_active добавили руками, ни одна миграция
 * её не создавала. Здесь она появляется официально. Проверка hasColumn
 * оставлена на случай базы, где колонка уже есть.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('clients', 'is_active')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('telegram_nickname');
            });
        }

        Schema::table('clients', function (Blueprint $table) {
            // name — это логин на VPN-сервере, двух одинаковых быть не может
            $table->unique('name');
            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_active']);
            $table->dropUnique(['name']);
            $table->dropColumn('is_active');
        });
    }
};

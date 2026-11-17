<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Роль покупателя VPN должна существовать с первого запуска: бот прикрепляет
 * её каждому новому юзеру, а крон списывает только с её обладателей.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('roles')->where('slug', 'consumer')->exists()) {
            return;
        }

        DB::table('roles')->insert([
            'slug' => 'consumer',
            'name' => 'Consumer',
            'permissions' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Роль не удаляем: к ней привязаны юзеры
    }
};

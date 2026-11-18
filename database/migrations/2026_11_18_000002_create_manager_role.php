<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Роль «Менеджер» — админ с урезанными правами: входит в админку и работает
 * с клиентами, покупателями и транзакциями (platform.vpn), но не управляет
 * пользователями админки и ролями. Полные права — у владельца (orchid:admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('roles')->where('slug', 'manager')->exists()) {
            return;
        }

        DB::table('roles')->insert([
            'slug' => 'manager',
            'name' => 'Менеджер',
            'permissions' => json_encode([
                'platform.index' => true,
                'platform.vpn' => true,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Роль не удаляем: к ней могут быть привязаны люди
    }
};

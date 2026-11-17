<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * На проде сидер не нужен: роль consumer создаёт миграция, админа —
     * orchid:admin. Демо-данные — только локально.
     */
    public function run(): void
    {
        User::consumerRole();

        if (app()->environment('local')) {
            $this->call(DevSeeder::class);
        }
    }
}

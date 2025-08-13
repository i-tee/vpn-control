<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Связь с пользователем
            $table->enum('type', ['deposit', 'withdraw']); // Тип: пополнение или списание
            $table->decimal('amount', 10, 2); // Сумма (положительная всегда, знак определяется типом)
            $table->string('subject_type')->nullable(); // Тип субъекта (TopUp, VpnService, ManualBonus)
            $table->unsignedBigInteger('subject_id')->nullable(); // ID субъекта
            $table->text('comment')->nullable(); // Комментарий
            $table->boolean('is_active')->default(true); // Активна ли транзакция
            $table->timestamps(); // created_at, updated_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
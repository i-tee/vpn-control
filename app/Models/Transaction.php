<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Orchid\Screen\AsSource;

class Transaction extends Model
{
    use HasFactory, AsSource;

    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'subject_type',
        'subject_id',
        'comment',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'amount' => 'float',
    ];

    protected $allowedSorts = [
        'id',
        'user_id',
        'created_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function createTransaction(
        int $userId,
        string $type,
        float $amount,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $comment = null,
        bool $isActive = true
    ): self {
        return self::create([
            'user_id' => $userId,
            'type' => $type,
            'amount' => $amount,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'comment' => $comment,
            'is_active' => $isActive,
        ]);
    }

    public function updateTransaction(
        ?string $type = null,
        ?float $amount = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $comment = null,
        ?bool $isActive = null
    ): bool {
        $data = array_filter([
            'type' => $type,
            'amount' => $amount,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'comment' => $comment,
            'is_active' => $isActive,
        ], fn($value) => !is_null($value));
        
        return $this->update($data);
    }

    public function cancel(string $comment = 'Отмена транзакции'): bool
    {
        if (!$this->is_active) {
            return false;
        }
        
        return $this->update([
            'is_active' => false,
            'comment' => $this->comment ? $this->comment . ' | ' . $comment : $comment,
        ]);
    }
}
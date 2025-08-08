<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Orchid\Filters\Filterable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Client extends Model
{
    use Filterable;
    
    // Список полей, которые можно фильтровать
    protected $allowedFilters = [
        'id',
        'name',
        'server_name',
        'telegram_nickname'
    ];
    
    // Список полей, которые можно сортировать
    protected $allowedSorts = [
        'id',
        'name',
        'created_at',
        'updated_at'
    ];

    protected $fillable = [
        'name',
        'password',
        'user_id',
        'server_name',
        'owner_id',
        'telegram_nickname'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
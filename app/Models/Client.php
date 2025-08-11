<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Orchid\Filters\Filterable;
use Orchid\Screen\AsSource;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Client extends Model
{
    use AsSource, Filterable;
    
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
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Orchid\Filters\Filterable;
use Orchid\Screen\AsSource;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\WhereDateStartEnd;

class Client extends Model
{
    use AsSource, Filterable;

    protected $fillable = [
        'name',
        'password',
        'user_id',
        'server_name',
        'owner_id',
        'telegram_nickname',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    // Для сортировки через URL
    protected $allowedSorts = [
        'id',
        'name',
        'user_id',
        'server_name',
        'created_at',
        'is_active',
    ];

    // Для фильтров через URL
    protected $allowedFilters = [
        'id'          => Where::class,
        'name'        => Like::class,
        'user_id'     => Where::class,
        'server_name' => Like::class,
        'created_at'  => WhereDateStartEnd::class,
        'is_active'   => Where::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
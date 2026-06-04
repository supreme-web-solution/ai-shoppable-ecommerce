<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveShowRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'live_show_id',
        'full_name',
        'email',
        'registered_at',
        'last_joined_at',
        'join_count',
        'max_watch_ms',
        'reached_half_at',
        'watched_to_end_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'last_joined_at' => 'datetime',
            'join_count' => 'integer',
            'max_watch_ms' => 'integer',
            'reached_half_at' => 'datetime',
            'watched_to_end_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function liveShow(): BelongsTo
    {
        return $this->belongsTo(LiveShow::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(LiveShowMessage::class);
    }
}

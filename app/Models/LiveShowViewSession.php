<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveShowViewSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'live_show_id',
        'viewer_key',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function liveShow(): BelongsTo
    {
        return $this->belongsTo(LiveShow::class);
    }
}

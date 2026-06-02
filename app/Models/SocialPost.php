<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPost extends Model
{
    protected $fillable = [
        'team_id',
        'video_id',
        'playlist_id',
        'embed_id',
        'zernio_post_id',
        'status',
        'caption',
        'shop_url',
        'platforms',
        'scheduled_for',
        'published_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'platforms' => 'array',
            'scheduled_for' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function embed(): BelongsTo
    {
        return $this->belongsTo(Embed::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatSessionBan extends Model
{
    protected $fillable = [
        'team_id',
        'video_id',
        'session_key',
        'banned_by_user_id',
        'reason',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function bannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by_user_id');
    }
}

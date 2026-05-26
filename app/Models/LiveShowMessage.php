<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveShowMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'live_show_id',
        'live_show_registration_id',
        'sender_type',
        'sender_name',
        'message',
        'is_pinned',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function liveShow(): BelongsTo
    {
        return $this->belongsTo(LiveShow::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(LiveShowRegistration::class, 'live_show_registration_id');
    }
}

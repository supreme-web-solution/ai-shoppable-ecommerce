<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    protected $fillable = [
        'team_id',
        'platform',
        'zernio_account_id',
        'zernio_profile_id',
        'platform_username',
        'connected_at',
    ];

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            '_id' => $this->zernio_account_id,
            'id' => $this->zernio_account_id,
            'platform' => $this->platform,
            'username' => $this->platform_username,
            'displayName' => $this->platform_username,
        ];
    }
}

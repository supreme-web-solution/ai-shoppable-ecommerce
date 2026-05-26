<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Playlist extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'creator_user_id',
        'title',
        'slug',
        'description',
        'is_active',
        'is_public',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    public function videos(): BelongsToMany
    {
        return $this->belongsToMany(Video::class)
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function embeds(): HasMany
    {
        return $this->hasMany(Embed::class);
    }
}

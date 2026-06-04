<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveShow extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'video_id',
        'title',
        'description',
        'status',
        'starts_at',
        'ends_at',
        'is_premiere',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_premiere' => 'boolean',
            'settings' => 'array',
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

    public function featuredProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'live_show_products')
            ->withPivot(['starts_at_ms', 'ends_at_ms', 'pin_order', 'flash_discount', 'appearance', 'cta_url'])
            ->withTimestamps();
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(LiveShowRegistration::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(LiveShowMessage::class);
    }
}

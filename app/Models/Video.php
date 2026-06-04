<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'creator_user_id',
        'title',
        'description',
        'source',
        'status',
        'visibility',
        'cloudinary_public_id',
        'playback_url',
        'thumbnail_url',
        'duration_seconds',
        'published_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'metadata' => 'array',
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

    public function productTags(): HasMany
    {
        return $this->hasMany(VideoProductTag::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class)
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function displayTitle(): string
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        foreach ([
            data_get($metadata, 'display_title'),
            data_get($metadata, 'original_name'),
            data_get($metadata, 'original_filename'),
            data_get($metadata, 'upload_name'),
        ] as $candidate) {
            if (is_string($candidate) && ($name = trim($candidate)) !== '' && ! self::looksLikeOpaqueId($name)) {
                return $name;
            }
        }

        $title = trim((string) $this->title);

        if ($title !== '' && ! self::looksLikeOpaqueId($title)) {
            return $title;
        }

        $publicId = trim((string) $this->cloudinary_public_id);

        if ($publicId !== '') {
            $leaf = trim((string) basename(str_replace('\\', '/', $publicId)));

            if ($leaf !== '' && str_starts_with($leaf, 'video_') && ctype_digit(substr($leaf, 6))) {
                return 'Video #'.substr($leaf, 6);
            }

            if ($leaf !== '' && ! self::looksLikeOpaqueId($leaf)) {
                return self::humanizeLabel($leaf);
            }
        }

        return 'Video #'.$this->id;
    }

    public static function looksLikeOpaqueId(string $value): bool
    {
        if (preg_match('/^[a-f0-9]{24,}$/i', $value)) {
            return true;
        }

        if (strlen($value) >= 28 && ! str_contains($value, ' ') && preg_match('/^[a-zA-Z0-9_-]+$/', $value)) {
            return true;
        }

        return false;
    }

    protected static function humanizeLabel(string $value): string
    {
        $base = pathinfo($value, PATHINFO_FILENAME);

        return ucwords(str_replace(['_', '-'], ' ', $base));
    }
}

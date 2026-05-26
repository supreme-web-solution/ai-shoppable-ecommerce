<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class VideoProductTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_id',
        'product_id',
        'starts_at_ms',
        'ends_at_ms',
        'cta_label',
        'position',
        'discount_percent',
        'is_pinned',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'array',
            'discount_percent' => 'decimal:2',
            'is_pinned' => 'boolean',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

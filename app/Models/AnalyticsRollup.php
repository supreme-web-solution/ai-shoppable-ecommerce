<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class AnalyticsRollup extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'video_id',
        'metric_date',
        'metric_name',
        'value_unsigned',
        'value_decimal',
        'dimensions',
    ];

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'value_decimal' => 'decimal:2',
            'dimensions' => 'array',
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
}

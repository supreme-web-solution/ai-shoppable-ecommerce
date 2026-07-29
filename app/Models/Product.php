<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'external_id',
        'source',
        'product_type',
        'digital_access_type',
        'digital_access_url',
        'digital_file_name',
        'title',
        'slug',
        'description',
        'image_url',
        'currency',
        'price',
        'sale_price',
        'sku',
        'inventory',
        'metadata',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'metadata' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function isDigital(): bool
    {
        return $this->product_type === 'digital';
    }

    public function hasDigitalAccess(): bool
    {
        return $this->isDigital()
            && filled($this->digital_access_url)
            && in_array($this->digital_access_type, ['file', 'link'], true);
    }

    /**
     * @return array{product_type: string, digital_access_type: ?string, digital_access_url: ?string, digital_file_name: ?string}
     */
    public function digitalAccessSnapshot(): array
    {
        if (! $this->isDigital()) {
            return [
                'product_type' => 'physical',
                'digital_access_type' => null,
                'digital_access_url' => null,
                'digital_file_name' => null,
            ];
        }

        return [
            'product_type' => 'digital',
            'digital_access_type' => $this->digital_access_type,
            'digital_access_url' => $this->digital_access_url,
            'digital_file_name' => $this->digital_file_name,
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function videoTags(): HasMany
    {
        return $this->hasMany(VideoProductTag::class);
    }
}

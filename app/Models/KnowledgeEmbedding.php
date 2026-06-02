<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KnowledgeEmbedding extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'owner_type',
        'owner_id',
        'source_index',
        'source_title',
        'chunk_index',
        'chunk_content',
        'embedding_json',
    ];

    protected function casts(): array
    {
        return [
            'embedding_json' => 'array',
        ];
    }
}

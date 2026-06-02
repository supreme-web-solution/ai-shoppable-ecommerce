<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedTinyInteger('source_index')->default(0);
            $table->string('source_title')->nullable();
            $table->unsignedInteger('chunk_index')->default(0);
            $table->text('chunk_content');
            $table->json('embedding_json');
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->unique(['owner_type', 'owner_id', 'source_index', 'chunk_index'], 'knowledge_embeddings_owner_chunk_unique');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement('ALTER TABLE knowledge_embeddings ADD COLUMN embedding_vector vector(1536)');
        DB::statement('CREATE INDEX knowledge_embeddings_owner_idx ON knowledge_embeddings(owner_type, owner_id)');
        DB::statement('CREATE INDEX knowledge_embeddings_vector_idx ON knowledge_embeddings USING ivfflat (embedding_vector vector_cosine_ops) WITH (lists = 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_embeddings');
    }
};

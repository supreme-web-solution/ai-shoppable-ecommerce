<?php

namespace App\Services\Ai;

use App\Models\KnowledgeEmbedding;
use App\Models\LiveShow;
use App\Models\Video;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class KnowledgeEmbeddingPipelineService
{
    public function __construct(
        protected KnowledgeSourceService $knowledgeSourceService,
        protected KnowledgeChunkingService $knowledgeChunkingService,
        protected KnowledgeEmbeddingProvider $knowledgeEmbeddingProvider,
    ) {}

    public function refreshVideo(Video $video): void
    {
        $sources = $this->knowledgeSourceService->forVideo($video);
        $this->refreshOwner(
            teamId: (int) $video->team_id,
            ownerType: 'video',
            ownerId: (int) $video->id,
            sources: $sources,
        );
    }

    public function refreshLiveShow(LiveShow $liveShow): void
    {
        $sources = $this->knowledgeSourceService->forLiveShow($liveShow);
        $this->refreshOwner(
            teamId: (int) $liveShow->team_id,
            ownerType: 'live_show',
            ownerId: (int) $liveShow->id,
            sources: $sources,
        );
    }

    public function contextForVideo(Video $video, string $question, int $limit = 4): string
    {
        return $this->contextForOwner(
            ownerType: 'video',
            ownerId: (int) $video->id,
            question: $question,
            fallbackSources: $this->knowledgeSourceService->forVideo($video),
            limit: $limit,
        );
    }

    public function contextForLiveShow(LiveShow $liveShow, string $question, int $limit = 4): string
    {
        return $this->contextForOwner(
            ownerType: 'live_show',
            ownerId: (int) $liveShow->id,
            question: $question,
            fallbackSources: $this->knowledgeSourceService->forLiveShow($liveShow),
            limit: $limit,
        );
    }

    /**
     * @param  array<int, array{title: string, content: string}>  $sources
     */
    protected function refreshOwner(int $teamId, string $ownerType, int $ownerId, array $sources): void
    {
        KnowledgeEmbedding::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->delete();

        $chunks = $this->knowledgeChunkingService->chunkSources($sources);
        if (empty($chunks)) {
            return;
        }

        $vectors = $this->knowledgeEmbeddingProvider->embed(array_map(
            static fn (array $chunk): string => (string) $chunk['content'],
            $chunks,
        ));

        $usePgVector = $this->supportsPgVector();
        foreach ($chunks as $index => $chunk) {
            $vector = $vectors[$index] ?? [];

            $embedding = KnowledgeEmbedding::query()->create([
                'team_id' => $teamId,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'source_index' => (int) $chunk['source_index'],
                'source_title' => (string) $chunk['source_title'],
                'chunk_index' => (int) $chunk['chunk_index'],
                'chunk_content' => (string) $chunk['content'],
                'embedding_json' => $vector,
            ]);

            if ($usePgVector && ! empty($vector)) {
                $this->updatePgVectorColumn((int) $embedding->id, $vector);
            }
        }
    }

    /**
     * @param  array<int, array{title: string, content: string}>  $fallbackSources
     */
    protected function contextForOwner(
        string $ownerType,
        int $ownerId,
        string $question,
        array $fallbackSources,
        int $limit = 4,
    ): string {
        $question = trim($question);
        if ($question === '') {
            return $this->knowledgeSourceService->toKnowledgeText($fallbackSources);
        }

        $queryVector = $this->knowledgeEmbeddingProvider->embed([$question])[0] ?? [];

        $matches = $this->supportsPgVector()
            ? $this->searchByPgVector($ownerType, $ownerId, $queryVector, $limit)
            : $this->searchByCosine($ownerType, $ownerId, $queryVector, $limit);

        if ($matches->isEmpty()) {
            return $this->knowledgeSourceService->toKnowledgeText($fallbackSources);
        }

        return $matches
            ->map(function (KnowledgeEmbedding $match): string {
                $title = trim((string) ($match->source_title ?? 'Knowledge'));
                $content = trim((string) $match->chunk_content);

                return sprintf("## %s\n%s", $title, $content);
            })
            ->implode("\n\n---\n\n");
    }

    /**
     * @return Collection<int, KnowledgeEmbedding>
     */
    protected function searchByCosine(string $ownerType, int $ownerId, array $queryVector, int $limit): Collection
    {
        $rows = KnowledgeEmbedding::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->get();

        if ($rows->isEmpty() || empty($queryVector)) {
            return collect();
        }

        return $rows
            ->map(function (KnowledgeEmbedding $row) use ($queryVector): array {
                $rowVector = is_array($row->embedding_json) ? $row->embedding_json : [];

                return [
                    'row' => $row,
                    'score' => $this->cosineSimilarity($queryVector, $rowVector),
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('row')
            ->values();
    }

    /**
     * @return Collection<int, KnowledgeEmbedding>
     */
    protected function searchByPgVector(string $ownerType, int $ownerId, array $queryVector, int $limit): Collection
    {
        if (empty($queryVector)) {
            return collect();
        }

        $vectorLiteral = $this->toPgVectorLiteral($queryVector);
        $rows = DB::table('knowledge_embeddings')
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->whereNotNull('embedding_vector')
            ->orderByRaw('embedding_vector <=> ?::vector', [$vectorLiteral])
            ->limit($limit)
            ->get();

        return collect($rows)
            ->map(fn (object $row): KnowledgeEmbedding => KnowledgeEmbedding::newFromBuilder((array) $row))
            ->values();
    }

    /**
     * @param  array<int, float|int|string>  $a
     * @param  array<int, float|int|string>  $b
     */
    protected function cosineSimilarity(array $a, array $b): float
    {
        $length = min(count($a), count($b));
        if ($length === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < $length; $i++) {
            $va = (float) $a[$i];
            $vb = (float) $b[$i];
            $dot += $va * $vb;
            $normA += $va * $va;
            $normB += $vb * $vb;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    protected function supportsPgVector(): bool
    {
        return DB::getDriverName() === 'pgsql' && Schema::hasColumn('knowledge_embeddings', 'embedding_vector');
    }

    /**
     * @param  array<int, float|int|string>  $vector
     */
    protected function updatePgVectorColumn(int $embeddingId, array $vector): void
    {
        DB::statement(
            'UPDATE knowledge_embeddings SET embedding_vector = ?::vector WHERE id = ?',
            [$this->toPgVectorLiteral($vector), $embeddingId],
        );
    }

    /**
     * @param  array<int, float|int|string>  $vector
     */
    protected function toPgVectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(
            static fn (float|int|string $value): string => (string) (float) $value,
            $vector,
        )).']';
    }
}

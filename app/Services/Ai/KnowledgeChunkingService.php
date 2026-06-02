<?php

namespace App\Services\Ai;

class KnowledgeChunkingService
{
    /**
     * @param  array<int, array{title: string, content: string}>  $sources
     * @return array<int, array{source_index: int, source_title: string, chunk_index: int, content: string}>
     */
    public function chunkSources(array $sources, int $maxChars = 1100, int $overlapChars = 150): array
    {
        $chunks = [];

        foreach ($sources as $sourceIndex => $source) {
            $parts = $this->chunkText((string) $source['content'], $maxChars, $overlapChars);
            foreach ($parts as $chunkIndex => $content) {
                $chunks[] = [
                    'source_index' => $sourceIndex,
                    'source_title' => (string) $source['title'],
                    'chunk_index' => $chunkIndex,
                    'content' => $content,
                ];
            }
        }

        return $chunks;
    }

    /**
     * @return array<int, string>
     */
    protected function chunkText(string $text, int $maxChars, int $overlapChars): array
    {
        $normalized = preg_replace('/\s+/', ' ', trim($text)) ?? '';
        if ($normalized === '') {
            return [];
        }

        if (mb_strlen($normalized) <= $maxChars) {
            return [$normalized];
        }

        $chunks = [];
        $start = 0;
        $length = mb_strlen($normalized);

        while ($start < $length) {
            $slice = mb_substr($normalized, $start, $maxChars);
            if ($slice === '') {
                break;
            }

            $chunks[] = trim($slice);
            $start += max(1, $maxChars - $overlapChars);
        }

        return $chunks;
    }
}

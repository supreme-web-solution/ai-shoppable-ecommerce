<?php

namespace App\Services\Ai;

use App\Models\LiveShow;
use App\Models\Video;
use Illuminate\Support\Collection;

class KnowledgeSourceService
{
    /**
     * @return array<int, array{title: string, content: string}>
     */
    public function forVideo(Video $video): array
    {
        $metadata = is_array($video->metadata) ? $video->metadata : [];

        return $this->normalizeSources($metadata);
    }

    /**
     * @return array<int, array{title: string, content: string}>
     */
    public function forLiveShow(LiveShow $liveShow): array
    {
        $settings = is_array($liveShow->settings) ? $liveShow->settings : [];

        return $this->normalizeSources($settings);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{title: string, content: string}>
     */
    public function normalizeSources(array $payload): array
    {
        $rawSources = data_get($payload, 'knowledge_sources', []);
        $sources = collect(is_array($rawSources) ? $rawSources : [])
            ->take(3)
            ->map(function (mixed $source): ?array {
                if (! is_array($source)) {
                    return null;
                }

                $title = trim((string) ($source['title'] ?? ''));
                $content = trim((string) ($source['content'] ?? ''));

                if ($title === '' || $content === '') {
                    return null;
                }

                return [
                    'title' => $title,
                    'content' => $content,
                ];
            })
            ->filter()
            ->values();

        if ($sources->isNotEmpty()) {
            /** @var array<int, array{title: string, content: string}> $normalized */
            $normalized = $sources->all();

            return $normalized;
        }

        $fallback = trim((string) data_get($payload, 'knowledge_base_text', ''));
        if ($fallback !== '') {
            return [[
                'title' => 'Knowledge Hub',
                'content' => $fallback,
            ]];
        }

        return [];
    }

    /**
     * @param  array<int, array{title: string, content: string}>  $sources
     */
    public function toKnowledgeText(array $sources): string
    {
        return Collection::make($sources)
            ->map(fn (array $source): string => sprintf("## %s\n%s", $source['title'], trim($source['content'])))
            ->implode("\n\n---\n\n");
    }
}

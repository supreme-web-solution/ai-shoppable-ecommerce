<?php

namespace App\Jobs;

use App\Models\LiveShow;
use App\Models\Video;
use App\Services\Ai\KnowledgeEmbeddingPipelineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshKnowledgeEmbeddingsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $ownerType,
        public int $ownerId,
    ) {
        $this->onQueue(config('queue.names.embeddings', 'embeddings'));
    }

    public function handle(KnowledgeEmbeddingPipelineService $pipeline): void
    {
        if ($this->ownerType === 'video') {
            $video = Video::query()->find($this->ownerId);
            if ($video) {
                $pipeline->refreshVideo($video);
            }

            return;
        }

        if ($this->ownerType === 'live_show') {
            $liveShow = LiveShow::query()->find($this->ownerId);
            if ($liveShow) {
                $pipeline->refreshLiveShow($liveShow);
            }
        }
    }
}

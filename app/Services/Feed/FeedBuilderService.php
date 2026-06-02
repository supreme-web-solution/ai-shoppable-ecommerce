<?php

namespace App\Services\Feed;

use App\Models\Embed;
use App\Models\Video;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FeedBuilderService
{
    public function forTeam(int $teamId, int $perPage = 10): LengthAwarePaginator
    {
        return Video::query()
            ->where('team_id', $teamId)
            ->where('status', 'published')
            ->with(['productTags.product.variants'])
            ->orderByDesc('published_at')
            ->paginate($perPage);
    }

    public function forEmbed(Embed $embed, int $perPage = 10): LengthAwarePaginator
    {
        $query = Video::query()
            ->where('team_id', $embed->team_id)
            ->where('status', 'published')
            ->with(['productTags.product.variants']);

        if ($embed->video_id) {
            $query->where('id', $embed->video_id);
        } elseif ($embed->playlist_id) {
            $playlistId = (int) $embed->playlist_id;

            $query
                ->join('playlist_video', function ($join) use ($playlistId): void {
                    $join->on('videos.id', '=', 'playlist_video.video_id')
                        ->where('playlist_video.playlist_id', '=', $playlistId);
                })
                ->select('videos.*')
                ->orderBy('playlist_video.sort_order');
        } else {
            $query->orderByDesc('published_at');
        }

        return $query->paginate($perPage);
    }
}

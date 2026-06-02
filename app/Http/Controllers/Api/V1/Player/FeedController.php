<?php

namespace App\Http\Controllers\Api\V1\Player;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\VideoResource;
use App\Models\Embed;
use App\Services\Feed\FeedBuilderService;
use App\Support\PlaylistPlaybackSettings;
use App\Support\TeamApiAuthorizer;
use Illuminate\Http\Request;

class FeedController extends Controller
{
    protected function resolveEmbedSlug(Request $request): string
    {
        $embedSlug = trim((string) $request->string('embed_slug', ''));

        if ($embedSlug !== '') {
            return $embedSlug;
        }

        $headerSlug = trim((string) $request->header('X-Embed-Slug', ''));

        return $headerSlug;
    }

    public function index(Request $request, FeedBuilderService $feedBuilderService, TeamApiAuthorizer $authorizer)
    {
        $perPage = min($request->integer('per_page', 10), 30);

        $embedSlug = $this->resolveEmbedSlug($request);

        if ($embedSlug !== '') {
            $embed = Embed::query()
                ->with('playlist')
                ->where('slug', $embedSlug)
                ->where('is_active', true)
                ->firstOrFail();

            $authorizer->assertPlayerAccess($request, $embed->team_id, $embed);

            $paginator = $feedBuilderService->forEmbed($embed, $perPage);

            $additional = [];

            if ($embed->playlist_id && $embed->playlist) {
                $additional['playlist_playback'] = PlaylistPlaybackSettings::normalize(
                    $embed->playlist->settings,
                );
            }

            return VideoResource::collection($paginator)->additional($additional);
        }

        $teamId = $request->integer('team_id');
        abort_if($teamId === 0, 422, 'team_id or embed_slug is required');
        $authorizer->assertPlayerAccess($request, $teamId);

        $paginator = $feedBuilderService->forTeam($teamId, $perPage);

        return VideoResource::collection($paginator);
    }
}

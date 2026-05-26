<?php

namespace App\Http\Controllers\Api\V1\Player;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LiveShowResource;
use App\Models\Embed;
use App\Models\LiveShow;
use App\Support\TeamApiAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveShowController extends Controller
{
    public function current(Request $request, TeamApiAuthorizer $authorizer): JsonResponse
    {
        $validated = $request->validate([
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'embed_slug' => ['nullable', 'string', 'max:255'],
            'video_id' => ['nullable', 'integer', 'exists:videos,id'],
        ]);

        $teamId = null;
        $embed = null;

        if (! empty($validated['embed_slug'])) {
            $embed = Embed::query()
                ->where('slug', $validated['embed_slug'])
                ->where('is_active', true)
                ->firstOrFail();
            $teamId = $embed->team_id;
            $authorizer->assertPlayerAccess($request, $teamId, $embed);
        } elseif (! empty($validated['team_id'])) {
            $teamId = (int) $validated['team_id'];
            $authorizer->assertPlayerAccess($request, $teamId);
        }

        abort_if($teamId === null, 422, 'team_id or embed_slug is required.');

        $baseQuery = LiveShow::query()
            ->where('team_id', $teamId)
            ->when(! empty($validated['video_id']), fn ($query) => $query->where('video_id', $validated['video_id']))
            ->with('featuredProducts');

        $liveShow = (clone $baseQuery)
            ->where('status', 'live')
            ->orderBy('starts_at')
            ->first();

        if (! $liveShow) {
            $liveShow = (clone $baseQuery)
                ->where('status', 'scheduled')
                ->where('starts_at', '>=', now()->subHours(4))
                ->orderBy('starts_at')
                ->first();
        }

        if (! $liveShow) {
            return response()->json([
                'data' => null,
            ]);
        }

        return response()->json([
            'data' => [
                ...(new LiveShowResource($liveShow))->resolve(),
                'state' => $liveShow->status,
                'countdown_seconds' => $liveShow->status === 'scheduled'
                    ? (int) max(now()->diffInSeconds($liveShow->starts_at, false), 0)
                    : 0,
            ],
        ]);
    }
}

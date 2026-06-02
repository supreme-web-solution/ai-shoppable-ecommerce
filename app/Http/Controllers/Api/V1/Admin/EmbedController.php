<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEmbedRequest;
use App\Http\Resources\Api\V1\EmbedResource;
use App\Models\Embed;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmbedController extends Controller
{
    protected function resolveTeamId(Request $request): int
    {
        $teamId = $request->integer('team_id', $request->user()->team_id);
        abort_unless($request->user()->team_id === $teamId || $request->user()->teams()->whereKey($teamId)->exists(), 403);

        return $teamId;
    }

    public function index(Request $request)
    {
        $teamId = $this->resolveTeamId($request);

        $perPage = min($request->integer('per_page', 15), 200);

        $embeds = Embed::query()
            ->where('team_id', $teamId)
            ->when($request->filled('playlist_id'), fn ($query) => $query->where('playlist_id', $request->integer('playlist_id')))
            ->when($request->filled('video_id'), fn ($query) => $query->where('video_id', $request->integer('video_id')))
            ->latest()
            ->paginate($perPage);

        return EmbedResource::collection($embeds);
    }

    public function store(StoreEmbedRequest $request)
    {
        abort_unless(
            $request->user()->team_id === (int) $request->input('team_id')
                || $request->user()->teams()->whereKey((int) $request->input('team_id'))->exists(),
            403,
        );

        $embed = Embed::query()->create([
            ...$request->validated(),
            'signed_key' => hash('sha256', Str::uuid()->toString().Str::random(16)),
        ]);

        return new EmbedResource($embed);
    }

    public function show(Embed $embed)
    {
        $this->authorize('view', $embed);

        return new EmbedResource($embed);
    }

    public function update(Request $request, Embed $embed)
    {
        $this->authorize('update', $embed);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'in:vertical_feed,floating_widget,carousel,product_page'],
            'playlist_id' => ['sometimes', 'nullable', 'integer', 'exists:playlists,id'],
            'video_id' => ['sometimes', 'nullable', 'integer', 'exists:videos,id'],
            'is_active' => ['sometimes', 'boolean'],
            'allowed_domains' => ['sometimes', 'array'],
            'settings' => ['sometimes', 'array'],
        ]);

        $embed->update($validated);

        return new EmbedResource($embed->fresh());
    }

    public function destroy(Embed $embed)
    {
        $this->authorize('delete', $embed);
        $embed->delete();

        return response()->noContent();
    }
}

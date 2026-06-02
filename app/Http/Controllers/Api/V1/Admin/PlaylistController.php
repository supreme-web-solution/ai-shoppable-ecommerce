<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePlaylistRequest;
use App\Http\Resources\Api\V1\PlaylistResource;
use App\Models\Playlist;
use Illuminate\Http\Request;

class PlaylistController extends Controller
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
        $perPage = min($request->integer('per_page', 12), 100);
        $search = trim((string) $request->string('search', ''));

        $query = Playlist::query()
            ->where('team_id', $teamId)
            ->with('videos')
            ->latest();

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($builder) use ($like): void {
                $builder
                    ->where('title', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }

        return PlaylistResource::collection($query->paginate($perPage));
    }

    public function store(StorePlaylistRequest $request)
    {
        abort_unless(
            $request->user()->team_id === (int) $request->input('team_id')
                || $request->user()->teams()->whereKey((int) $request->input('team_id'))->exists(),
            403,
        );

        $playlist = Playlist::query()->create([
            ...$request->validated(),
            'creator_user_id' => $request->user()?->id,
        ]);

        $videoIds = collect($request->input('video_ids', []))
            ->values()
            ->mapWithKeys(fn ($videoId, $index) => [$videoId => ['sort_order' => $index]])
            ->all();

        if ($videoIds !== []) {
            $playlist->videos()->sync($videoIds);
        }

        return new PlaylistResource($playlist->fresh('videos'));
    }

    public function show(Playlist $playlist)
    {
        $this->authorize('view', $playlist);

        return new PlaylistResource($playlist->load('videos'));
    }

    public function update(Request $request, Playlist $playlist)
    {
        $this->authorize('update', $playlist);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.auto_advance_enabled' => ['sometimes', 'boolean'],
            'settings.loops_per_video' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'video_ids' => ['sometimes', 'array'],
            'video_ids.*' => ['integer', 'exists:videos,id'],
        ]);

        $playlist->update(collect($validated)->except('video_ids')->all());

        if (array_key_exists('video_ids', $validated)) {
            $syncData = collect($validated['video_ids'])
                ->values()
                ->mapWithKeys(fn ($videoId, $index) => [$videoId => ['sort_order' => $index]])
                ->all();

            $playlist->videos()->sync($syncData);
        }

        return new PlaylistResource($playlist->fresh('videos'));
    }

    public function destroy(Playlist $playlist)
    {
        $this->authorize('delete', $playlist);
        $playlist->delete();

        return response()->noContent();
    }
}

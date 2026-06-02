<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AiGenerationResource;
use App\Http\Resources\Api\V1\VideoResource;
use App\Jobs\GenerateAvatarVideoJob;
use App\Models\AiGeneration;
use App\Models\Video;
use App\Services\Ai\AiAvatarVideoService;
use App\Services\Ai\AiScriptGeneratorService;
use App\Services\Ai\MultilingualVideoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AiContentController extends Controller
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

        $generations = AiGeneration::query()
            ->where('team_id', $teamId)
            ->latest()
            ->paginate(20);

        return AiGenerationResource::collection($generations);
    }

    public function show(Request $request, AiGeneration $generation)
    {
        $user = $request->user();

        abort_unless(
            $user
            && (
                $user->team_id === $generation->team_id
                || $user->teams()->whereKey($generation->team_id)->exists()
            ),
            403,
        );

        return new AiGenerationResource($generation);
    }

    public function heygenOptions(Request $request, AiAvatarVideoService $avatarVideoService)
    {
        $this->resolveTeamId($request);

        return response()->json($avatarVideoService->options($request->boolean('refresh')));
    }

    public function generateScript(Request $request, AiScriptGeneratorService $scriptGeneratorService)
    {
        $teamId = $this->resolveTeamId($request);

        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'topic' => ['nullable', 'string', 'max:255'],
            'tone' => ['nullable', 'string', 'in:engaging,luxury,urgent,friendly'],
            'language' => ['nullable', 'string', 'max:10'],
            'duration_seconds' => ['nullable', 'integer', 'min:15', 'max:180'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);

        abort_unless($teamId === (int) $validated['team_id'], 403);

        $output = $scriptGeneratorService->generate([
            ...$validated,
            'team_id' => $teamId,
        ]);

        $generation = AiGeneration::query()->create([
            'team_id' => $teamId,
            'user_id' => $request->user()?->id,
            'type' => 'script',
            'provider' => (string) ($output['provider'] ?? 'template'),
            'status' => 'completed',
            'input' => $validated,
            'output' => $output,
            'completed_at' => now(),
        ]);

        return new AiGenerationResource($generation);
    }

    public function generateAvatarVideo(Request $request)
    {
        $teamId = $this->resolveTeamId($request);

        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'script' => ['required', 'string', 'max:5000'],
            'language' => ['nullable', 'string', 'max:10'],
            'avatar_id' => ['nullable', 'string', 'max:100'],
            'voice_id' => ['nullable', 'string', 'max:100'],
            'enable_embed_overlays' => ['nullable', 'boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'publish_when_ready' => ['nullable', 'boolean'],
            'product_placement_enabled' => ['nullable', 'boolean'],
            'product_placement_image_url' => ['nullable', 'url', 'max:2000'],
            'product_placement_position' => ['nullable', 'in:top_left,top_right,bottom_left,bottom_right'],
            'product_placement_scale' => ['nullable', 'numeric', 'gt:0', 'max:2'],
            'product_placement_opacity' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'product_placement_offset_x' => ['nullable', 'numeric', 'min:-1', 'max:1'],
            'product_placement_offset_y' => ['nullable', 'numeric', 'min:-1', 'max:1'],
            'product_placement_motion_prompt' => ['nullable', 'string', 'max:400'],
        ]);

        abort_unless($teamId === (int) $validated['team_id'], 403);

        $video = Video::query()->create([
            'team_id' => $teamId,
            'creator_user_id' => $request->user()?->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'source' => 'ai_generated',
            'status' => 'processing',
            'visibility' => 'public',
            'metadata' => [
                'language' => $validated['language'] ?? 'en',
                'avatar_id' => $validated['avatar_id'] ?? null,
                'voice_id' => $validated['voice_id'] ?? null,
                'enable_embed_overlays' => (bool) ($validated['enable_embed_overlays'] ?? true),
                'product_ids' => $validated['product_ids'] ?? [],
                'product_placement' => [
                    'enabled' => (bool) ($validated['product_placement_enabled'] ?? false),
                    'image_url' => $validated['product_placement_image_url'] ?? null,
                    'position' => $validated['product_placement_position'] ?? 'bottom_right',
                    'scale' => isset($validated['product_placement_scale']) ? (float) $validated['product_placement_scale'] : 0.3,
                    'opacity' => isset($validated['product_placement_opacity']) ? (float) $validated['product_placement_opacity'] : 1.0,
                    'offset_x' => isset($validated['product_placement_offset_x']) ? (float) $validated['product_placement_offset_x'] : 0,
                    'offset_y' => isset($validated['product_placement_offset_y']) ? (float) $validated['product_placement_offset_y'] : 0,
                    'motion_prompt' => $validated['product_placement_motion_prompt'] ?? null,
                ],
            ],
        ]);

        $generation = AiGeneration::query()->create([
            'team_id' => $teamId,
            'user_id' => $request->user()?->id,
            'video_id' => $video->id,
            'type' => 'avatar_video',
            'provider' => trim((string) config('services.heygen.api_key')) !== '' ? 'heygen' : 'mock',
            'status' => 'queued',
            'input' => $validated,
        ]);

        $queue = (string) config('queue.names.ai', 'ai');
        $connection = (string) config('queue.default', 'sync');

        Log::info('AI avatar video queued', [
            'generation_id' => $generation->id,
            'video_id' => $video->id,
            'team_id' => $teamId,
            'provider' => $generation->provider,
            'queue_connection' => $connection,
            'queue' => $queue,
            'avatar_id' => $validated['avatar_id'] ?? null,
            'voice_id' => $validated['voice_id'] ?? null,
            'product_ids' => $validated['product_ids'] ?? [],
        ]);

        GenerateAvatarVideoJob::dispatch($generation->id)
            ->onQueue($queue);

        return response()->json([
            'generation' => new AiGenerationResource($generation),
            'video' => new VideoResource($video),
        ], 202);
    }

    public function generateMultilingualVideos(Request $request, MultilingualVideoService $multilingualVideoService)
    {
        $teamId = $this->resolveTeamId($request);

        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'script' => ['required', 'string', 'max:5000'],
            'languages' => ['required', 'array', 'min:1', 'max:8'],
            'languages.*' => ['string', 'max:10'],
            'avatar_id' => ['nullable', 'string', 'max:100'],
            'voice_id' => ['nullable', 'string', 'max:100'],
            'enable_embed_overlays' => ['nullable', 'boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'publish_when_ready' => ['nullable', 'boolean'],
            'product_placement_enabled' => ['nullable', 'boolean'],
            'product_placement_image_url' => ['nullable', 'url', 'max:2000'],
            'product_placement_position' => ['nullable', 'in:top_left,top_right,bottom_left,bottom_right'],
            'product_placement_scale' => ['nullable', 'numeric', 'gt:0', 'max:2'],
            'product_placement_opacity' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'product_placement_offset_x' => ['nullable', 'numeric', 'min:-1', 'max:1'],
            'product_placement_offset_y' => ['nullable', 'numeric', 'min:-1', 'max:1'],
            'product_placement_motion_prompt' => ['nullable', 'string', 'max:400'],
        ]);

        abort_unless($teamId === (int) $validated['team_id'], 403);

        $result = $multilingualVideoService->queue(
            $validated,
            $teamId,
            $request->user()?->id,
        );

        return response()->json([
            'batch' => new AiGenerationResource($result['batch']),
            'videos' => collect($result['videos'])->map(fn (array $row) => [
                'language' => $row['language'],
                'video' => new VideoResource($row['video']),
                'generation' => new AiGenerationResource($row['generation']),
            ])->values(),
        ], 202);
    }

    public function uploadProductPlacementImage(Request $request)
    {
        $teamId = $this->resolveTeamId($request);

        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'file' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ]);

        abort_unless($teamId === (int) $validated['team_id'], 403);

        $path = $request->file('file')->store('uploads/product-placement', 'public');

        return response()->json([
            'path' => $path,
            'url' => Storage::url($path),
            'filename' => Str::afterLast($path, '/'),
        ]);
    }
}

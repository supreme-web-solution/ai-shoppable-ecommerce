<?php

namespace App\Services\Ai;

use App\Jobs\GenerateAvatarVideoJob;
use App\Models\AiGeneration;
use App\Models\Video;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MultilingualVideoService
{
    public function __construct(
        protected AiTranslationService $translationService,
        protected AiAvatarVideoService $avatarVideoService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{batch: AiGeneration, videos: array<int, array{language: string, video: Video, generation: AiGeneration}>}
     */
    public function queue(array $validated, int $teamId, ?int $userId): array
    {
        $languages = collect($validated['languages'] ?? [])
            ->map(fn ($code) => strtolower(trim((string) $code)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        abort_if(count($languages) === 0, 422, 'At least one target language is required.');

        $baseTitle = (string) $validated['title'];
        $baseScript = (string) $validated['script'];
        $preferredVoiceId = trim((string) ($validated['voice_id'] ?? ''));

        $batch = AiGeneration::query()->create([
            'team_id' => $teamId,
            'user_id' => $userId,
            'type' => 'multilingual_batch',
            'provider' => trim((string) config('services.heygen.api_key')) !== '' ? 'heygen' : 'mock',
            'status' => 'processing',
            'input' => $validated,
            'output' => ['languages' => $languages, 'items' => []],
        ]);

        $results = [];
        $queue = (string) config('queue.names.ai', 'ai');

        foreach ($languages as $language) {
            $translated = $this->translationService->translateScript($baseScript, $language);
            $voiceId = $preferredVoiceId !== ''
                ? $preferredVoiceId
                : $this->avatarVideoService->resolveVoiceIdForLanguage(
                    $language,
                    '',
                    (string) ($validated['avatar_id'] ?? ''),
                );

            Log::info('Multilingual avatar video voice resolved', [
                'batch_id' => $batch->id,
                'language' => $language,
                'voice_id' => $voiceId,
                'used_preferred_voice' => $preferredVoiceId !== '',
            ]);

            $titleSuffix = AiTranslationService::languageLabel($language);
            $video = Video::query()->create([
                'team_id' => $teamId,
                'creator_user_id' => $userId,
                'title' => Str::limit($baseTitle.' — '.$titleSuffix, 255, ''),
                'description' => $validated['description'] ?? null,
                'source' => 'ai_generated',
                'status' => 'processing',
                'visibility' => 'public',
                'metadata' => [
                    'language' => $language,
                    'locale' => $language,
                    'multilingual_batch_id' => $batch->id,
                    'avatar_id' => $validated['avatar_id'] ?? null,
                    'voice_id' => $voiceId ?: ($validated['voice_id'] ?? null),
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
                    'translation_provider' => $translated['provider'],
                ],
            ]);

            $generation = AiGeneration::query()->create([
                'team_id' => $teamId,
                'user_id' => $userId,
                'video_id' => $video->id,
                'type' => 'avatar_video',
                'provider' => $batch->provider,
                'status' => 'queued',
                'input' => [
                    ...$validated,
                    'language' => $language,
                    'script' => $translated['script'],
                    'voice_id' => $voiceId,
                    'title' => $video->title,
                    'parent_batch_id' => $batch->id,
                ],
            ]);

            GenerateAvatarVideoJob::dispatch($generation->id)->onQueue($queue);

            $results[] = [
                'language' => $language,
                'video' => $video,
                'generation' => $generation,
                'translation_provider' => $translated['provider'],
            ];
        }

        $batch->update([
            'output' => [
                'languages' => $languages,
                'items' => collect($results)->map(fn (array $row): array => [
                    'language' => $row['language'],
                    'video_id' => $row['video']->id,
                    'generation_id' => $row['generation']->id,
                    'translation_provider' => $row['translation_provider'],
                ])->all(),
            ],
        ]);

        return [
            'batch' => $batch->fresh(),
            'videos' => $results,
        ];
    }
}

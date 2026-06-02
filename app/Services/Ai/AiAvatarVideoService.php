<?php

namespace App\Services\Ai;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiAvatarVideoService
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function submit(array $input): array
    {
        $apiKey = $this->apiKey();

        if ($apiKey !== '') {
            Log::info('HeyGen avatar video submit started', [
                'provider' => 'heygen',
                'title' => $input['title'] ?? null,
                'avatar_id' => $input['avatar_id'] ?? null,
                'voice_id' => $input['voice_id'] ?? null,
                'product_ids' => $input['product_ids'] ?? [],
                'product_placement_enabled' => (bool) ($input['product_placement_enabled'] ?? false),
                'product_placement_image_url' => $input['product_placement_image_url'] ?? null,
                'script_length' => strlen((string) ($input['script'] ?? '')),
            ]);

            $remote = $this->submitToHeyGen($apiKey, $input);
            if ($remote !== null) {
                Log::info('HeyGen avatar video submit accepted', [
                    'external_id' => $remote['external_id'] ?? null,
                    'avatar_id' => $remote['avatar_id'] ?? null,
                    'voice_id' => $remote['voice_id'] ?? null,
                    'visual_background' => $remote['visual_background'] ?? null,
                ]);

                return $remote;
            }

            Log::warning('HeyGen avatar video submit failed, falling back to mock provider');
        }

        Log::info('Mock avatar video submission created', [
            'title' => $input['title'] ?? null,
            'script_length' => strlen((string) ($input['script'] ?? '')),
        ]);

        return $this->mockSubmission($input);
    }

    /**
     * @return array{enabled: bool, avatars: array<int, array<string, mixed>>, voices: array<int, array<string, mixed>>, cached_at: string|null, message: string|null}
     */
    public function options(bool $refresh = false): array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === '') {
            return [
                'enabled' => false,
                'avatars' => [],
                'voices' => [],
                'cached_at' => null,
                'message' => 'Set HEYGEN_API_KEY to load HeyGen avatars and voices.',
            ];
        }

        $cacheKey = 'heygen.options.'.substr(hash('sha256', $apiKey), 0, 16);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addSeconds((int) config('services.heygen.cache_ttl_seconds', 21600)), function () use ($apiKey): array {
            $avatars = $this->fetchAvatarLooks($apiKey);
            $voices = $this->fetchVoices($apiKey);

            return [
                'enabled' => true,
                'avatars' => $avatars,
                'voices' => $voices,
                'cached_at' => now()->toIso8601String(),
                'message' => $avatars || $voices ? null : 'HeyGen did not return any avatars or voices for this API key.',
            ];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function poll(string $provider, string $externalId): ?array
    {
        if ($provider === 'heygen') {
            return $this->pollHeyGen($externalId);
        }

        if ($provider === 'mock') {
            return [
                'status' => 'completed',
                'playback_url' => url('/storage/mock/avatar_'.$externalId.'.mp4'),
                'thumbnail_url' => url('/storage/mock/avatar_'.$externalId.'.jpg'),
                'duration_seconds' => (int) config('services.ai.default_avatar_duration', 45),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    protected function submitToHeyGen(string $apiKey, array $input): ?array
    {
        $payload = $this->buildVideoPayload($input);

        if ($payload === null) {
            Log::warning('HeyGen avatar video payload could not be built', [
                'avatar_id' => $input['avatar_id'] ?? null,
                'voice_id' => $input['voice_id'] ?? null,
            ]);

            return null;
        }

        try {
            Log::info('Sending avatar video request to HeyGen', [
                'endpoint' => 'POST /v3/videos',
                'title' => $payload['title'] ?? null,
                'avatar_id' => $payload['avatar_id'] ?? null,
                'voice_id' => $payload['voice_id'] ?? null,
                'aspect_ratio' => $payload['aspect_ratio'] ?? null,
                'fit' => $payload['fit'] ?? null,
                'has_watermark' => isset($payload['watermark']),
                'has_motion_prompt' => isset($payload['motion_prompt']),
                'script_length' => strlen((string) ($payload['script'] ?? '')),
            ]);

            $response = Http::timeout(30)
                ->withHeaders(['x-api-key' => $apiKey])
                ->post('https://api.heygen.com/v3/videos', $payload);

            if (! $response->successful()) {
                Log::warning('HeyGen avatar video request was rejected', [
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 800),
                ]);

                return null;
            }

            $videoId = (string) data_get($response->json(), 'data.video_id', '');

            if ($videoId === '') {
                Log::warning('HeyGen avatar video response missing video_id', [
                    'body' => Str::limit($response->body(), 800),
                ]);

                return null;
            }

            return [
                'provider' => 'heygen',
                'external_id' => $videoId,
                'status' => 'processing',
                'avatar_id' => $payload['avatar_id'],
                'voice_id' => $payload['voice_id'] ?? null,
            ];
        } catch (\Throwable $exception) {
            Log::warning('HeyGen avatar video request threw exception', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function pollHeyGen(string $externalId): ?array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            return null;
        }

        try {
            $response = Http::timeout(20)
                ->withHeaders(['x-api-key' => $apiKey])
                ->get("https://api.heygen.com/v3/videos/{$externalId}");

            if (! $response->successful()) {
                Log::warning('HeyGen avatar video poll request failed', [
                    'external_id' => $externalId,
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 800),
                ]);

                return null;
            }

            $payload = $response->json();
            $status = strtolower((string) data_get($payload, 'data.status', 'processing'));

            Log::info('HeyGen avatar video poll response received', [
                'external_id' => $externalId,
                'status' => $status,
                'failure_code' => data_get($payload, 'data.failure_code'),
                'failure_message' => data_get($payload, 'data.failure_message'),
            ]);

            if ($status !== 'completed') {
                return [
                    'status' => $status === 'failed' ? 'failed' : 'processing',
                    'error_message' => (string) (data_get($payload, 'data.failure_message') ?: data_get($payload, 'data.failure_code', '')),
                ];
            }

            return [
                'status' => 'completed',
                'playback_url' => (string) data_get($payload, 'data.video_url', ''),
                'thumbnail_url' => (string) data_get($payload, 'data.thumbnail_url', ''),
                'duration_seconds' => (int) data_get($payload, 'data.duration', 45),
            ];
        } catch (\Throwable $exception) {
            Log::warning('HeyGen avatar video poll threw exception', [
                'external_id' => $externalId,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function mockSubmission(array $input): array
    {
        $externalId = Str::lower(Str::random(12));

        return [
            'provider' => 'mock',
            'external_id' => $externalId,
            'status' => 'processing',
            'mock' => true,
            'script_preview' => Str::limit((string) ($input['script'] ?? ''), 120),
        ];
    }

    protected function apiKey(): string
    {
        return trim((string) config('services.heygen.api_key'));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    protected function buildVideoPayload(array $input): ?array
    {
        $avatarId = $this->resolveAvatarId($input);

        if ($avatarId === '') {
            return null;
        }

        $voiceId = $this->resolveVoiceId($input, $avatarId);
        $payload = [
            'type' => 'avatar',
            'avatar_id' => $avatarId,
            'title' => (string) ($input['title'] ?? 'AI product presenter'),
            'script' => (string) ($input['script'] ?? ''),
            'resolution' => '1080p',
            'aspect_ratio' => '9:16',
            'fit' => 'cover',
        ];

        if ($voiceId !== '') {
            $payload['voice_id'] = $voiceId;
        }

        $productPlacementEnabled = (bool) ($input['product_placement_enabled'] ?? false);
        $productPlacementImageUrl = trim((string) ($input['product_placement_image_url'] ?? ''));
        if ($productPlacementEnabled && $productPlacementImageUrl !== '') {
            $watermark = [
                'image' => [
                    'type' => 'url',
                    'url' => $productPlacementImageUrl,
                ],
                'placement' => [
                    'position' => (string) ($input['product_placement_position'] ?? 'bottom_right'),
                    'offset_x' => isset($input['product_placement_offset_x']) ? (float) $input['product_placement_offset_x'] : 0,
                    'offset_y' => isset($input['product_placement_offset_y']) ? (float) $input['product_placement_offset_y'] : 0,
                ],
                'scale' => isset($input['product_placement_scale']) ? (float) $input['product_placement_scale'] : 0.3,
                'opacity' => isset($input['product_placement_opacity']) ? (float) $input['product_placement_opacity'] : 1,
            ];

            $payload['watermark'] = $watermark;

            $motionPrompt = trim((string) ($input['product_placement_motion_prompt'] ?? ''));
            if ($motionPrompt !== '') {
                $payload['motion_prompt'] = $motionPrompt;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function resolveAvatarId(array $input): string
    {
        $avatarId = trim((string) ($input['avatar_id'] ?? ''));

        if ($avatarId !== '') {
            return $avatarId;
        }

        $cachedAvatarId = (string) data_get($this->options(), 'avatars.0.id', '');
        if ($cachedAvatarId !== '') {
            return $cachedAvatarId;
        }

        $configured = trim((string) config('services.heygen.default_avatar_id', ''));
        if ($configured !== '') {
            return $configured;
        }

        return '';
    }

    public function resolveVoiceIdForLanguage(
        string $languageCode,
        string $preferredVoiceId = '',
        string $avatarId = '',
    ): string {
        if ($preferredVoiceId !== '') {
            return $preferredVoiceId;
        }

        $label = AiTranslationService::languageLabel($languageCode);
        $options = $this->options();
        $voices = collect($options['voices'] ?? []);

        $matched = $voices->first(function (array $voice) use ($label, $languageCode): bool {
            $voiceLanguage = strtolower((string) ($voice['language'] ?? ''));

            return $voiceLanguage !== ''
                && (
                    str_contains($voiceLanguage, strtolower($label))
                    || str_contains($voiceLanguage, strtolower($languageCode))
                );
        });

        if ($matched) {
            return (string) ($matched['voice_id'] ?? '');
        }

        if ($avatarId !== '') {
            return $this->resolveVoiceId(['voice_id' => ''], $avatarId);
        }

        return (string) data_get($voices->first(), 'voice_id', '');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function resolveVoiceId(array $input, string $avatarId): string
    {
        $language = strtolower(trim((string) ($input['language'] ?? '')));

        if ($language !== '') {
            $localized = $this->resolveVoiceIdForLanguage(
                $language,
                trim((string) ($input['voice_id'] ?? '')),
                $avatarId,
            );

            if ($localized !== '') {
                return $localized;
            }
        }

        $voiceId = trim((string) ($input['voice_id'] ?? ''));

        if ($voiceId !== '') {
            return $voiceId;
        }

        $options = $this->options();
        $avatar = collect($options['avatars'] ?? [])->firstWhere('id', $avatarId);
        $avatarDefaultVoice = trim((string) data_get($avatar, 'default_voice_id', ''));

        if ($avatarDefaultVoice !== '') {
            return $avatarDefaultVoice;
        }

        $cachedVoiceId = (string) data_get($options, 'voices.0.voice_id', '');
        if ($cachedVoiceId !== '') {
            return $cachedVoiceId;
        }

        return trim((string) config('services.heygen.default_voice_id', ''));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchAvatarLooks(string $apiKey): array
    {
        $avatarTypes = [null, 'studio_avatar', 'photo_avatar', 'digital_twin'];

        return collect(['private', 'public'])
            ->flatMap(fn (string $ownership): Collection => collect($avatarTypes)
                ->flatMap(fn (?string $avatarType): array => $this->fetchAvatarLooksForOwnership($apiKey, $ownership, $avatarType)))
            ->unique('id')
            ->sortBy(fn (array $avatar): string => ($avatar['ownership'] === 'private' ? '0' : '1').($avatar['preferred_orientation'] === 'portrait' ? '0' : '1').($avatar['preview_image_url'] ? '0' : '1').$avatar['name'])
            ->take(120)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchAvatarLooksForOwnership(string $apiKey, string $ownership, ?string $avatarType = null): array
    {
        try {
            $avatars = collect();
            $token = null;

            for ($page = 0; $page < 2; $page++) {
                $params = array_filter([
                    'ownership' => $ownership,
                    'avatar_type' => $avatarType,
                    'limit' => 50,
                    'token' => $token,
                ], fn (mixed $value): bool => $value !== null && $value !== '');

                $response = Http::timeout(20)
                    ->withHeaders(['x-api-key' => $apiKey])
                    ->get('https://api.heygen.com/v3/avatars/looks', $params);

                if (! $response->successful()) {
                    break;
                }

                $payload = $response->json();
                $avatars = $avatars->merge((array) data_get($payload, 'data', []));
                $token = data_get($payload, 'next_token');

                if (! data_get($payload, 'has_more') || ! $token) {
                    break;
                }
            }

            return $avatars
                ->filter(fn (mixed $avatar): bool => is_array($avatar) && in_array(data_get($avatar, 'status'), [null, 'completed'], true))
                ->map(fn (array $avatar): array => [
                    'id' => (string) data_get($avatar, 'id', ''),
                    'name' => (string) data_get($avatar, 'name', 'HeyGen avatar'),
                    'avatar_type' => (string) data_get($avatar, 'avatar_type', ''),
                    'gender' => data_get($avatar, 'gender'),
                    'preview_image_url' => data_get($avatar, 'preview_image_url'),
                    'preview_video_url' => data_get($avatar, 'preview_video_url'),
                    'default_voice_id' => data_get($avatar, 'default_voice_id'),
                    'preferred_orientation' => data_get($avatar, 'preferred_orientation'),
                    'supported_api_engines' => (array) data_get($avatar, 'supported_api_engines', []),
                    'ownership' => $ownership,
                ])
                ->filter(fn (array $avatar): bool => $avatar['id'] !== '')
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchVoices(string $apiKey): array
    {
        return collect(['private', 'public'])
            ->flatMap(fn (string $type): array => $this->fetchVoicesForType($apiKey, $type))
            ->unique('voice_id')
            ->sortBy(fn (array $voice): string => ($voice['language'] === 'English' ? '0' : '1').($voice['preview_audio_url'] ? '0' : '1').$voice['name'])
            ->take(60)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchVoicesForType(string $apiKey, string $type): array
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders(['x-api-key' => $apiKey])
                ->get('https://api.heygen.com/v3/voices', [
                    'type' => $type,
                    'language' => 'English',
                    'limit' => 100,
                ]);

            if (! $response->successful()) {
                return [];
            }

            return collect((array) data_get($response->json(), 'data', []))
                ->filter(fn (mixed $voice): bool => is_array($voice))
                ->map(fn (array $voice): array => [
                    'voice_id' => (string) data_get($voice, 'voice_id', ''),
                    'name' => (string) data_get($voice, 'name', 'HeyGen voice'),
                    'language' => (string) data_get($voice, 'language', ''),
                    'gender' => (string) data_get($voice, 'gender', ''),
                    'preview_audio_url' => data_get($voice, 'preview_audio_url'),
                    'support_pause' => (bool) data_get($voice, 'support_pause', false),
                    'support_locale' => (bool) data_get($voice, 'support_locale', false),
                    'type' => (string) data_get($voice, 'type', $type),
                ])
                ->filter(fn (array $voice): bool => $voice['voice_id'] !== '')
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}

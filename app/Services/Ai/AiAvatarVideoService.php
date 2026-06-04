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
                'custom_background_enabled' => (bool) ($input['custom_background_enabled'] ?? false),
                'background_color' => $input['background_color'] ?? null,
                'script_length' => strlen((string) ($input['script'] ?? '')),
            ]);

            $remote = $this->submitToHeyGen($apiKey, $input);

            Log::info('HeyGen avatar video submit accepted', [
                'external_id' => $remote['external_id'] ?? null,
                'avatar_id' => $remote['avatar_id'] ?? null,
                'voice_id' => $remote['voice_id'] ?? null,
                'visual_background' => $remote['visual_background'] ?? null,
            ]);

            return $remote;
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

        $options = Cache::remember($cacheKey, now()->addSeconds((int) config('services.heygen.cache_ttl_seconds', 21600)), function () use ($apiKey): array {
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

        return $options;
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
     * @return array<string, mixed>
     */
    protected function submitToHeyGen(string $apiKey, array $input): array
    {
        $payload = $this->buildVideoPayload($input);

        if ($payload === null) {
            Log::warning('HeyGen avatar video payload could not be built', [
                'avatar_id' => $input['avatar_id'] ?? null,
                'voice_id' => $input['voice_id'] ?? null,
            ]);

            throw new \RuntimeException('Could not build a HeyGen video request for the selected presenter.');
        }

        try {
            Log::info('Sending avatar video request to HeyGen', [
                'endpoint' => 'POST /v3/videos',
                'title' => $payload['title'] ?? null,
                'avatar_id' => $payload['avatar_id'] ?? null,
                'voice_id' => $payload['voice_id'] ?? null,
                'aspect_ratio' => $payload['aspect_ratio'] ?? null,
                'fit' => $payload['fit'] ?? null,
                'background' => $payload['background'] ?? null,
                'remove_background' => $payload['remove_background'] ?? false,
                'script_length' => strlen((string) ($payload['script'] ?? '')),
            ]);

            $response = Http::timeout(30)
                ->withHeaders(['x-api-key' => $apiKey])
                ->post('https://api.heygen.com/v3/videos', $payload);

            if (! $response->successful()) {
                $message = (string) data_get($response->json(), 'error.message', 'HeyGen rejected the video request.');

                Log::warning('HeyGen avatar video request was rejected', [
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 800),
                    'avatar_id' => $payload['avatar_id'] ?? null,
                ]);

                throw new \RuntimeException($message);
            }

            $videoId = (string) data_get($response->json(), 'data.video_id', '');

            if ($videoId === '') {
                Log::warning('HeyGen avatar video response missing video_id', [
                    'body' => Str::limit($response->body(), 800),
                ]);

                throw new \RuntimeException('HeyGen accepted the request but did not return a video_id.');
            }

            return [
                'provider' => 'heygen',
                'external_id' => $videoId,
                'status' => 'processing',
                'avatar_id' => $payload['avatar_id'],
                'voice_id' => $payload['voice_id'] ?? null,
            ];
        } catch (\RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('HeyGen avatar video request threw exception', [
                'message' => $exception->getMessage(),
            ]);

            throw new \RuntimeException('HeyGen request failed: '.$exception->getMessage(), 0, $exception);
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

        $avatarMeta = $this->resolveAvatarMeta($input, $avatarId);
        if (! $this->avatarSupportsV3Video($avatarMeta)) {
            throw new \RuntimeException(
                'The selected presenter is not compatible with HeyGen API video generation. Refresh the avatar list and choose a different look.',
            );
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

        $engine = $this->resolveEngine($input, $avatarId);
        if ($engine !== null) {
            $payload['engine'] = $engine;
        }

        $background = $this->resolveBackgroundSetting($input, $avatarId);
        if ($background !== null) {
            $payload['background'] = $background;
            // Photo looks ship with a baked-in scene; color alone is ignored unless
            // the avatar is matted onto the new background (HeyGen: remove_background).
            if ($this->shouldRemoveAvatarBackgroundForCustomColor($input, $avatarId)) {
                $payload['remove_background'] = true;
            }
            // cover fills the frame with the avatar look (often hiding a custom color).
            $payload['fit'] = 'contain';
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{type: string, value: string}|null
     */
    protected function resolveBackgroundSetting(array $input, string $avatarId): ?array
    {
        if (! (bool) ($input['custom_background_enabled'] ?? false)) {
            return null;
        }

        $color = $this->normalizeHexColor((string) ($input['background_color'] ?? ''));
        if ($color === '') {
            return null;
        }

        return [
            'type' => 'color',
            'value' => $color,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{type: string}|null
     */
    protected function resolveEngine(array $input, string $avatarId): ?array
    {
        $engines = $this->supportedApiEngines($this->resolveAvatarMeta($input, $avatarId));

        if (in_array('avatar_iv', $engines, true)) {
            return null;
        }

        if (in_array('avatar_v', $engines, true)) {
            return ['type' => 'avatar_v'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function shouldRemoveAvatarBackgroundForCustomColor(array $input, string $avatarId): bool
    {
        $meta = $this->resolveAvatarMeta($input, $avatarId);
        $avatarType = (string) ($meta['avatar_type'] ?? '');

        if ($avatarType === 'photo_avatar') {
            return true;
        }

        return in_array('avatar_iv', $this->supportedApiEngines($meta), true);
    }

    /**
     * @param  array<string, mixed>  $avatar
     * @return array<int, string>
     */
    protected function supportedApiEngines(array $avatar): array
    {
        return collect($avatar['supported_api_engines'] ?? [])
            ->map(fn (mixed $engine): string => strtolower(trim((string) $engine)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $avatar
     */
    protected function avatarSupportsV3Video(array $avatar): bool
    {
        $engines = $this->supportedApiEngines($avatar);

        return in_array('avatar_iv', $engines, true) || in_array('avatar_v', $engines, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function resolveAvatarMeta(array $input, string $avatarId): array
    {
        if ($avatarId === '') {
            return [];
        }

        $cached = collect($this->options()['avatars'] ?? [])->firstWhere('id', $avatarId);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        return $this->fetchAvatarLookMeta($avatarId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchAvatarLookMeta(string $avatarId): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            return [];
        }

        try {
            $response = Http::timeout(20)
                ->withHeaders(['x-api-key' => $apiKey])
                ->get("https://api.heygen.com/v3/avatars/looks/{$avatarId}");

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json('data');
            if (! is_array($data)) {
                return [];
            }

            $engines = (array) data_get($data, 'supported_api_engines', []);

            return [
                'id' => (string) data_get($data, 'id', ''),
                'name' => (string) data_get($data, 'name', 'HeyGen avatar'),
                'avatar_type' => (string) data_get($data, 'avatar_type', ''),
                'supported_api_engines' => $engines,
                'supports_v3_video' => $this->avatarSupportsV3Video(['supported_api_engines' => $engines]),
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    protected function normalizeHexColor(string $color): string
    {
        $color = trim($color);
        if ($color === '') {
            return '';
        }

        if (! str_starts_with($color, '#')) {
            $color = '#'.$color;
        }

        if (preg_match('/^#([A-Fa-f0-9]{6})$/', $color, $matches) === 1) {
            return '#'.strtolower($matches[1]);
        }

        if (preg_match('/^#([A-Fa-f0-9]{3})$/', $color, $matches) === 1) {
            $short = strtolower($matches[1]);

            return '#'.$short[0].$short[0].$short[1].$short[1].$short[2].$short[2];
        }

        return '';
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
                    'supports_v3_video' => $this->avatarSupportsV3Video([
                        'supported_api_engines' => (array) data_get($avatar, 'supported_api_engines', []),
                    ]),
                    'ownership' => $ownership,
                ])
                ->filter(fn (array $avatar): bool => $avatar['id'] !== '' && $avatar['supports_v3_video'])
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

<?php

namespace App\Services\Ai;

use App\Models\Product;
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
                'ad_style' => $this->adStyle($input),
                'has_visual_file' => filled($input['visual_file_path'] ?? null),
                'visual_url' => $input['visual_url'] ?? null,
                'product_ids' => $input['product_ids'] ?? [],
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
        $payload = $this->buildVideoPayload($input, $apiKey);

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
                'ad_style' => $this->adStyle($input),
                'has_visual_background' => data_get($payload, 'background.url') !== null || data_get($payload, 'background.asset_id') !== null,
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
                'ad_style' => $this->adStyle($input),
                'visual_background' => $payload['background'] ?? null,
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
    protected function buildVideoPayload(array $input, string $apiKey): ?array
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

        $motionPrompt = $this->motionPromptForStyle($input);
        if ($motionPrompt !== '') {
            $payload['motion_prompt'] = $motionPrompt;
            $payload['expressiveness'] = 'medium';
        }

        if ($voiceId !== '') {
            $payload['voice_id'] = $voiceId;
        }

        $background = $this->visualBackground($input, $apiKey);
        if ($background !== null) {
            $payload['background'] = $background;
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

    /**
     * @param  array<string, mixed>  $input
     */
    protected function resolveVoiceId(array $input, string $avatarId): string
    {
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
     * @param  array<string, mixed>  $input
     * @return array{type: string, url?: string, asset_id?: string}|null
     */
    protected function visualBackground(array $input, string $apiKey): ?array
    {
        $style = $this->adStyle($input);

        if ($style === 'avatar_only') {
            return null;
        }

        $assetId = $this->uploadVisualAsset($apiKey, (string) ($input['visual_file_path'] ?? ''));
        if ($assetId !== '') {
            return [
                'type' => 'image',
                'asset_id' => $assetId,
            ];
        }

        $visualUrl = $this->publicHttpsUrl((string) ($input['visual_url'] ?? ''));
        if ($visualUrl !== '') {
            return [
                'type' => 'image',
                'url' => $visualUrl,
            ];
        }

        $product = $this->resolveProducts($input)
            ->first(fn (Product $product): bool => $this->publicHttpsUrl((string) $product->image_url) !== '');

        if (! $product) {
            return null;
        }

        $url = $this->publicHttpsUrl((string) $product->image_url);

        return [
            'type' => 'image',
            'url' => $url,
        ];
    }

    protected function motionPromptForStyle(array $input): string
    {
        return match ($this->adStyle($input)) {
            'avatar_beside_product' => 'Present the product confidently and gesture toward the product visual beside you.',
            'product_card_overlay' => 'Speak like a short social commerce ad and gesture naturally as product details appear.',
            'full_product_background' => 'Stand in front of the product visual and explain the benefits with confident hand gestures.',
            'template_ad_scene' => 'Deliver an energetic product advertisement and gesture naturally toward the product scene.',
            default => '',
        };
    }

    protected function adStyle(array $input): string
    {
        return (string) ($input['ad_style'] ?? 'avatar_beside_product');
    }

    protected function publicHttpsUrl(string $url): string
    {
        $url = trim($url);

        if (! str_starts_with($url, 'https://')) {
            return '';
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
    }

    protected function uploadVisualAsset(string $apiKey, string $filePath): string
    {
        $filePath = trim($filePath);

        if (! $this->isReadableLocalVisualPath($filePath)) {
            return '';
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'Idempotency-Key' => 'ai-visual-'.substr(hash('sha256', $filePath.'|'.(string) filemtime($filePath)), 0, 32),
                ])
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post('https://api.heygen.com/v3/assets');

            if (! $response->successful()) {
                Log::warning('HeyGen visual asset upload failed', [
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 800),
                ]);

                return '';
            }

            $assetId = (string) data_get($response->json(), 'data.asset_id', '');

            Log::info('HeyGen visual asset uploaded', [
                'asset_id' => $assetId,
                'url' => data_get($response->json(), 'data.url'),
                'mime_type' => data_get($response->json(), 'data.mime_type'),
            ]);

            return $assetId;
        } catch (\Throwable $exception) {
            Log::warning('HeyGen visual asset upload threw exception', [
                'message' => $exception->getMessage(),
            ]);

            return '';
        }
    }

    protected function isReadableLocalVisualPath(string $filePath): bool
    {
        if ($filePath === '' || ! is_file($filePath) || ! is_readable($filePath)) {
            return false;
        }

        $realPath = realpath($filePath);
        $storagePath = realpath(storage_path());

        return $realPath !== false
            && $storagePath !== false
            && Str::startsWith($realPath, $storagePath);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return Collection<int, Product>
     */
    protected function resolveProducts(array $input): Collection
    {
        $teamId = (int) ($input['team_id'] ?? 0);
        $productIds = collect($input['product_ids'] ?? [])->filter()->values();

        if ($teamId < 1 || $productIds->isEmpty()) {
            return collect();
        }

        return Product::query()
            ->where('team_id', $teamId)
            ->whereIn('id', $productIds)
            ->get();
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

<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\Team;
use Illuminate\Validation\ValidationException;

class SocialPublishService
{
    public function __construct(
        protected SocialPostAdapter $adapter,
        protected SocialAccountConnectionService $connections,
    ) {}

    /**
     * @param  array<int, array{platform: string, accountId: string}>  $platformRows
     * @return array{
     *     content: string,
     *     platforms: array<int, array<string, mixed>>,
     *     adaptations: array<int, array<string, mixed>>
     * }
     */
    public function prepare(
        Team $team,
        string $fullCaption,
        array $platformRows,
        bool $hasMedia,
        ?string $mediaType = 'video',
        ?string $title = null,
    ): array {
        $fullCaption = trim($fullCaption);

        if ($fullCaption === '') {
            throw ValidationException::withMessages([
                'caption' => 'Caption is required.',
            ]);
        }

        $mediaRequired = config('social_publishing.media_required_platforms', []);
        $preparedPlatforms = [];
        $adaptations = [];

        foreach ($platformRows as $row) {
            $platform = strtolower(trim((string) ($row['platform'] ?? '')));
            $accountId = trim((string) ($row['accountId'] ?? $row['account_id'] ?? ''));

            if ($platform === '' || $accountId === '') {
                throw ValidationException::withMessages([
                    'platforms' => 'Each selected platform must include a platform and account id.',
                ]);
            }

            if ($platform === 'x') {
                $platform = 'twitter';
            }

            $connected = SocialAccount::query()
                ->where('team_id', $team->id)
                ->where('platform', $platform)
                ->where('zernio_account_id', $accountId)
                ->exists();

            if (! $connected) {
                throw ValidationException::withMessages([
                    'platforms' => 'Connect '.ucfirst($platform).' in Integrations before publishing.',
                ]);
            }

            if (in_array($platform, $mediaRequired, true) && ! $hasMedia) {
                throw ValidationException::withMessages([
                    'platforms' => ucfirst($platform).' requires a video or image attachment.',
                ]);
            }

            $adapted = $this->adapter->adapt($platform, $fullCaption, $mediaType, $title);

            $platformPayload = [
                'platform' => $platform,
                'accountId' => $accountId,
                'content' => $adapted['content'],
            ];

            if ($adapted['platformSpecificData'] !== []) {
                $platformPayload['platformSpecificData'] = $adapted['platformSpecificData'];
            }

            $preparedPlatforms[] = $platformPayload;
            $adaptations[] = [
                'platform' => $platform,
                'content' => $adapted['content'],
                'platformSpecificData' => $adapted['platformSpecificData'],
                'effective_length' => $this->adapter->effectiveLength($platform, $adapted['content'], $mediaType),
                'limit' => $this->adapter->limitForPlatform($platform, $mediaType),
            ];
        }

        return [
            'content' => $preparedPlatforms[0]['content'] ?? $fullCaption,
            'platforms' => $preparedPlatforms,
            'adaptations' => $adaptations,
        ];
    }

    public function friendlyErrorMessage(string $message): string
    {
        $normalized = strtolower($message);

        if (str_contains($normalized, 'duplicate') || str_contains($normalized, 'already posted')) {
            return 'This caption and media were already posted within the last 24 hours. Change the caption or wait before posting again.';
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function publishLimits(): array
    {
        return [
            'media_required_platforms' => config('social_publishing.media_required_platforms', []),
            'platform_content_limits' => config('social_publishing.platform_content_limits', []),
            'twitter_url_length' => config('social_publishing.twitter_url_length', 23),
        ];
    }
}

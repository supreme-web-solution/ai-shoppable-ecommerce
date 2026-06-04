<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Cloudinary\Configuration\ApiConfig;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CloudinaryService
{
    protected ?Cloudinary $cloudinary = null;

    protected ?string $cloudName = null;

    public function __construct()
    {
        $this->cloudName = trim((string) config('services.cloudinary.cloud_name'));
        $apiKey = trim((string) config('services.cloudinary.api_key'));
        $apiSecret = trim((string) config('services.cloudinary.api_secret'));

        if ($this->cloudName !== '' && $apiKey !== '' && $apiSecret !== '') {
            $uploadTimeout = (int) config('services.cloudinary.upload_timeout', 600);
            $chunkSize = (int) config('services.cloudinary.chunk_size', 6_000_000);

            $this->cloudinary = new Cloudinary([
                'cloud' => [
                    'cloud_name' => $this->cloudName,
                    'api_key' => $apiKey,
                    'api_secret' => $apiSecret,
                ],
                'api' => [
                    ApiConfig::UPLOAD_TIMEOUT => $uploadTimeout,
                    ApiConfig::TIMEOUT => min($uploadTimeout, 120),
                    ApiConfig::CHUNK_SIZE => $chunkSize,
                ],
            ]);

            Log::debug('CloudinaryService: configured', ['cloud_name' => $this->cloudName]);

            return;
        }

        Log::warning('CloudinaryService: credentials missing — uploads will use mock URLs');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function uploadImage(string $filePath, array $options = []): array
    {
        $publicId = Arr::get($options, 'public_id', 'image_'.Str::uuid()->toString());

        if ($this->cloudinary === null) {
            Log::warning('CloudinaryService: mock image upload (not configured)', [
                'file_path' => $filePath,
                'public_id' => $publicId,
            ]);

            return [
                'public_id' => $publicId,
                'secure_url' => url('/storage/mock/'.$publicId.'.jpg'),
                'used_mock' => true,
            ];
        }

        if (! file_exists($filePath)) {
            throw new \RuntimeException("Image file does not exist: {$filePath}");
        }

        Log::info('CloudinaryService: uploading image', [
            'file_path' => $filePath,
            'file_size_bytes' => filesize($filePath),
            'public_id' => $publicId,
            'cloud_name' => $this->cloudName,
        ]);

        $response = $this->cloudinary->uploadApi()->upload($filePath, array_merge([
            'resource_type' => 'image',
            'folder' => 'ai-video-commerce/products',
        ], $options));

        $result = $this->normalizeUploadResult($response);
        $publicId = (string) Arr::get($result, 'public_id');
        $secureUrl = $this->withImageDeliveryTransform((string) Arr::get($result, 'secure_url'));

        Log::info('CloudinaryService: image upload succeeded', [
            'public_id' => $publicId,
            'secure_url' => $secureUrl,
        ]);

        return [
            'public_id' => $publicId,
            'secure_url' => $secureUrl,
            'used_mock' => false,
            'raw' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function uploadVideo(string $filePath, array $options = []): array
    {
        $publicId = Arr::get($options, 'public_id', 'video_'.Str::uuid()->toString());

        if ($this->cloudinary === null) {
            Log::warning('CloudinaryService: mock upload (not configured)', [
                'file_path' => $filePath,
                'public_id' => $publicId,
            ]);

            return [
                'public_id' => $publicId,
                'secure_url' => url('/storage/mock/'.$publicId.'.mp4'),
                'duration' => 30,
                'thumbnail_url' => url('/storage/mock/'.$publicId.'.jpg'),
                'used_mock' => true,
            ];
        }

        if (! file_exists($filePath)) {
            throw new \RuntimeException("Video file does not exist: {$filePath}");
        }

        Log::info('CloudinaryService: uploading video', [
            'file_path' => $filePath,
            'file_size_bytes' => filesize($filePath),
            'public_id' => $publicId,
            'cloud_name' => $this->cloudName,
        ]);

        $uploadTimeout = (int) config('services.cloudinary.upload_timeout', 600);
        $chunkSize = (int) config('services.cloudinary.chunk_size', 6_000_000);

        $response = $this->cloudinary->uploadApi()->upload($filePath, array_merge([
            'resource_type' => 'video',
            'folder' => 'ai-video-commerce',
            ApiConfig::TIMEOUT => $uploadTimeout,
            ApiConfig::CHUNK_SIZE => $chunkSize,
        ], $options));

        $result = $this->normalizeUploadResult($response);

        $publicId = (string) Arr::get($result, 'public_id');
        $secureUrl = $this->withVideoDeliveryTransform((string) Arr::get($result, 'secure_url'));
        $thumbnailUrl = $this->videoThumbnailUrl($publicId, $result);

        Log::info('CloudinaryService: upload succeeded', [
            'public_id' => $publicId,
            'secure_url' => $secureUrl,
            'thumbnail_url' => $thumbnailUrl,
            'duration' => Arr::get($result, 'duration'),
        ]);

        return [
            'public_id' => $publicId,
            'secure_url' => $secureUrl,
            'duration' => (int) Arr::get($result, 'duration', 0),
            'thumbnail_url' => $thumbnailUrl,
            'used_mock' => false,
            'raw' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeUploadResult(mixed $response): array
    {
        if ($response instanceof \ArrayObject) {
            return $response->getArrayCopy();
        }

        if (is_array($response)) {
            return $response;
        }

        return (array) $response;
    }

    protected function withImageDeliveryTransform(string $url): string
    {
        if ($url === '' || ! str_contains($url, '/image/upload/')) {
            return $url;
        }

        if (str_contains($url, '/image/upload/f_auto')) {
            return $url;
        }

        return str_replace('/image/upload/', '/image/upload/f_auto,q_auto/', $url);
    }

    protected function withVideoDeliveryTransform(string $url): string
    {
        if ($url === '' || ! str_contains($url, '/video/upload/')) {
            return $url;
        }

        if (str_contains($url, '/video/upload/f_auto')) {
            return $url;
        }

        return str_replace('/video/upload/', '/video/upload/f_auto,q_auto/', $url);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function videoThumbnailUrl(string $publicId, array $result): string
    {
        $fromResult = Arr::get($result, 'thumbnail_url') ?? Arr::get($result, 'eager.0.secure_url');

        if (is_string($fromResult) && $fromResult !== '') {
            return $fromResult;
        }

        if ($this->cloudName === null || $this->cloudName === '') {
            return '';
        }

        $escapedId = str_replace('/', '%2F', $publicId);

        return "https://res.cloudinary.com/{$this->cloudName}/video/upload/so_0,w_400,h_711,c_fill/{$escapedId}.jpg";
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function verifyWebhook(array $context): array
    {
        $secret = trim((string) config('services.cloudinary.api_secret'));
        $body = (string) Arr::get($context, 'body', '');
        $timestamp = (string) Arr::get($context, 'timestamp', '');
        $signature = (string) Arr::get($context, 'signature', '');

        $verified = $secret !== ''
            && $body !== ''
            && $timestamp !== ''
            && $signature !== ''
            && abs(time() - (int) $timestamp) <= 7200
            && hash_equals(sha1($body.$timestamp.$secret), $signature);

        return [
            'verified' => $verified,
            'payload' => Arr::get($context, 'payload', []),
        ];
    }
}

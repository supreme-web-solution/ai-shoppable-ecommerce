<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\HandleCloudinaryWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CloudinaryWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloudinary_webhook_rejects_invalid_signature(): void
    {
        Queue::fake();
        config(['services.cloudinary.api_secret' => 'secret']);

        $payload = [
            'public_id' => 'video_123',
            'secure_url' => 'https://res.cloudinary.com/demo/video/upload/v1/video_123.mp4',
            'notification_type' => 'upload',
            'version' => 1,
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        $response = $this->call(
            'POST',
            '/api/v1/integrations/cloudinary/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CLD_TIMESTAMP' => $timestamp,
                'HTTP_X_CLD_SIGNATURE' => 'invalid-signature',
            ],
            $body,
        );

        $response->assertUnauthorized();
        Queue::assertNothingPushed();
    }

    public function test_cloudinary_webhook_is_idempotent_and_queues_once(): void
    {
        Queue::fake();
        config(['services.cloudinary.api_secret' => 'secret']);

        $payload = [
            'public_id' => 'video_123',
            'secure_url' => 'https://res.cloudinary.com/demo/video/upload/v1/video_123.mp4',
            'thumbnail_url' => 'https://res.cloudinary.com/demo/video/upload/v1/video_123.jpg',
            'duration' => 32,
            'notification_type' => 'upload',
            'version' => 1,
            'asset_id' => 'asset-123',
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = sha1($body.$timestamp.'secret');

        $first = $this->call(
            'POST',
            '/api/v1/integrations/cloudinary/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CLD_TIMESTAMP' => $timestamp,
                'HTTP_X_CLD_SIGNATURE' => $signature,
                'HTTP_X_CLD_REQUEST_ID' => 'req-123',
            ],
            $body,
        );

        $first->assertOk()->assertJson([
            'ok' => true,
            'provider' => 'cloudinary',
            'queued' => true,
        ]);

        $second = $this->call(
            'POST',
            '/api/v1/integrations/cloudinary/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CLD_TIMESTAMP' => $timestamp,
                'HTTP_X_CLD_SIGNATURE' => $signature,
                'HTTP_X_CLD_REQUEST_ID' => 'req-123',
            ],
            $body,
        );

        $second->assertOk()->assertJson([
            'ok' => true,
            'provider' => 'cloudinary',
            'duplicate' => true,
        ]);

        Queue::assertPushed(HandleCloudinaryWebhookJob::class, 1);

        $this->assertDatabaseHas('webhook_receipts', [
            'provider' => 'cloudinary',
            'event_key' => 'request:req-123',
        ]);
    }
}

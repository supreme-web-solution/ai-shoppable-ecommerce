<?php

namespace Tests\Feature\Api\V1;

use App\Models\Comment;
use App\Models\Embed;
use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LiveVideoChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_comment_creates_ai_reply_when_enabled(): void
    {
        [$team] = $this->createTeamWithOwner();

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'AI Live Chat Video',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
            'metadata' => [
                'ai_assistant_enabled' => true,
                'knowledge_base_text' => 'Shipping takes 2 days worldwide.',
            ],
        ]);

        Embed::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'name' => 'Live Video Embed',
            'type' => 'vertical_feed',
            'slug' => 'live-video-embed',
            'signed_key' => hash('sha256', 'live-video-embed'),
            'is_active' => true,
        ]);

        $response = $this->postJson(
            '/api/v1/player/comments',
            [
                'team_id' => $team->id,
                'video_id' => $video->id,
                'body' => 'How long is shipping?',
            ],
            [
                'X-Embed-Slug' => 'live-video-embed',
            ],
        );

        $response->assertCreated()
            ->assertJsonPath('metadata.sender_type', 'attendee')
            ->assertJsonPath('ai_replies.0.metadata.sender_type', 'ai');

        $this->assertDatabaseCount('comments', 2);
        $aiReply = Comment::query()
            ->where('video_id', $video->id)
            ->whereJsonContains('metadata->sender_type', 'ai')
            ->latest('id')
            ->first();
        $this->assertNotNull($aiReply);
        $this->assertStringContainsString('Shipping takes 2 days worldwide', (string) $aiReply?->body);
    }

    public function test_player_can_list_video_comments_and_broadcast_config(): void
    {
        [$team] = $this->createTeamWithOwner();

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Listed Video',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
        ]);

        Embed::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'name' => 'Listed Embed',
            'type' => 'vertical_feed',
            'slug' => 'listed-embed',
            'signed_key' => hash('sha256', 'listed-embed'),
            'is_active' => true,
        ]);

        Comment::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'body' => 'Hello from viewer',
            'metadata' => [
                'sender_type' => 'attendee',
                'sender_name' => 'Sam',
                'session_key' => 'embed-abc',
            ],
        ]);

        $this->getJson('/api/v1/player/broadcast-config')
            ->assertOk()
            ->assertJsonStructure(['enabled']);

        $this->getJson(
            "/api/v1/player/comments?team_id={$team->id}&video_id={$video->id}",
            ['X-Embed-Slug' => 'listed-embed'],
        )
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Hello from viewer')
            ->assertJsonPath('data.0.metadata.sender_name', 'Sam');
    }

    public function test_admin_can_manage_live_video_chat_threads(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Live Video Thread',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
        ]);

        Comment::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'body' => 'Is this available in blue?',
            'metadata' => [
                'sender_type' => 'attendee',
                'sender_name' => 'Viewer',
                'source' => 'live_video_chat',
            ],
        ]);

        $conversations = $this->getJson("/api/v1/admin/live-video-chats?team_id={$team->id}");
        $conversations->assertOk()
            ->assertJsonPath('data.0.video_id', $video->id);

        $postHostMessage = $this->postJson(
            "/api/v1/admin/live-video-chats/{$video->id}/messages?team_id={$team->id}",
            [
                'sender_name' => 'Host Jane',
                'message' => 'Yes, blue variation is in stock.',
            ],
        );

        $postHostMessage->assertCreated()
            ->assertJsonPath('data.sender_type', 'host');

        $messages = $this->getJson("/api/v1/admin/live-video-chats/{$video->id}/messages?team_id={$team->id}");
        $messages->assertOk()
            ->assertJsonPath('data.0.sender_type', 'attendee')
            ->assertJsonPath('data.1.sender_type', 'host');
    }

    /**
     * @return array{0: Team, 1: User}
     */
    protected function createTeamWithOwner(): array
    {
        $owner = User::factory()->create();

        $team = Team::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Team '.fake()->unique()->word(),
            'slug' => 'team-'.fake()->unique()->slug(),
            'checkout_mode' => 'hybrid',
            'external_provider' => 'none',
        ]);

        $team->users()->attach($owner->id, ['role' => 'owner']);
        $owner->update(['team_id' => $team->id]);

        return [$team, $owner];
    }
}

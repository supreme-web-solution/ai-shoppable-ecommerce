<?php

namespace Tests\Feature\Api\V1;

use App\Models\Embed;
use App\Models\LiveShow;
use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KnowledgeEmbeddingPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_ai_chat_uses_embedded_knowledge_sources(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Knowledge Video',
            'source' => 'uploaded',
            'status' => 'published',
            'visibility' => 'public',
        ]);

        $this->patchJson("/api/v1/admin/videos/{$video->id}", [
            'metadata' => [
                'ai_assistant_enabled' => true,
                'knowledge_sources' => [
                    [
                        'title' => 'Shipping policy',
                        'content' => 'Shipping within the US takes 2 business days.',
                    ],
                    [
                        'title' => 'Returns policy',
                        'content' => 'Returns are accepted within 30 days of delivery.',
                    ],
                    [
                        'title' => 'Support hours',
                        'content' => 'Support chat is available Monday to Friday, 9am to 5pm.',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('knowledge_embeddings', [
            'owner_type' => 'video',
            'owner_id' => $video->id,
        ]);

        Embed::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'name' => 'Knowledge Embed',
            'type' => 'vertical_feed',
            'slug' => 'knowledge-embed',
            'signed_key' => hash('sha256', 'knowledge-embed'),
            'is_active' => true,
        ]);

        $response = $this->postJson(
            '/api/v1/player/comments',
            [
                'team_id' => $team->id,
                'video_id' => $video->id,
                'body' => 'What is the return window?',
            ],
            ['X-Embed-Slug' => 'knowledge-embed'],
        );

        $response->assertCreated()
            ->assertJsonPath('ai_replies.0.metadata.sender_type', 'ai');

        $this->assertStringContainsString(
            '30 days',
            (string) $response->json('ai_replies.0.body'),
        );
    }

    public function test_webinar_ai_chat_uses_embedded_knowledge_sources(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $liveShow = LiveShow::query()->create([
            'team_id' => $team->id,
            'title' => 'Live AI Webinar',
            'status' => 'live',
            'starts_at' => now()->subMinutes(2),
            'settings' => [],
        ]);

        $this->patchJson("/api/v1/admin/live-shows/{$liveShow->id}", [
            'settings' => [
                'ai_assistant_enabled' => true,
                'knowledge_sources' => [
                    [
                        'title' => 'Bonus',
                        'content' => 'Attendees receive the replay link by email after the event.',
                    ],
                    [
                        'title' => 'Offer',
                        'content' => 'Use code LIVE20 for 20 percent off today only.',
                    ],
                    [
                        'title' => 'Timing',
                        'content' => 'Q&A starts in the final 15 minutes of the webinar.',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('knowledge_embeddings', [
            'owner_type' => 'live_show',
            'owner_id' => $liveShow->id,
        ]);

        $registration = $this->postJson("/api/v1/player/webinars/{$liveShow->id}/register", [
            'full_name' => 'Sam Viewer',
            'email' => 'sam@example.com',
        ])->assertOk()->json('data');

        $messageResponse = $this->postJson("/api/v1/player/webinars/{$liveShow->id}/messages", [
            'registration_id' => $registration['registration_id'],
            'message' => 'Will I get a replay after this?',
        ]);

        $messageResponse->assertCreated()
            ->assertJsonPath('data.1.sender_type', 'ai');

        $this->assertStringContainsString(
            'replay link',
            (string) $messageResponse->json('data.1.message'),
        );
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

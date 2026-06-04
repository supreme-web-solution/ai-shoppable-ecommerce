<?php

namespace Tests\Feature\Api\V1;

use App\Models\Comment;
use App\Models\LiveShow;
use App\Models\LiveShowMessage;
use App\Models\LiveShowRegistration;
use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatHubSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_counts_unique_chats_not_individual_messages(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();

        $liveShow = LiveShow::query()->create([
            'team_id' => $team->id,
            'title' => 'Chat Webinar',
            'status' => 'live',
            'starts_at' => now()->subMinute(),
        ]);

        $registration = LiveShowRegistration::query()->create([
            'live_show_id' => $liveShow->id,
            'full_name' => 'One Attendee',
            'email' => 'one@example.com',
            'registered_at' => now(),
        ]);

        foreach (['Hello', 'Follow up'] as $message) {
            LiveShowMessage::query()->create([
                'live_show_id' => $liveShow->id,
                'live_show_registration_id' => $registration->id,
                'sender_type' => 'attendee',
                'sender_name' => $registration->full_name,
                'message' => $message,
            ]);
        }

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Live Video',
            'source' => 'uploaded',
            'status' => 'ready',
            'visibility' => 'public',
        ]);

        Comment::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'session_key' => 'viewer-a',
            'body' => 'Nice video',
        ]);

        Comment::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'session_key' => 'viewer-a',
            'body' => 'Another line',
        ]);

        $this->actingAs($owner)
            ->getJson('/api/v1/admin/chats/summary')
            ->assertOk()
            ->assertJsonPath('data.webinar_chats_count', 1)
            ->assertJsonPath('data.live_video_chats_count', 1)
            ->assertJsonPath('data.total_chats_count', 2);
    }

    /**
     * @return array{0: Team, 1: User}
     */
    protected function createTeamWithOwner(): array
    {
        $owner = User::factory()->create();

        $team = Team::query()->create([
            'name' => 'Chat Team',
            'slug' => 'chat-team',
            'owner_user_id' => $owner->id,
            'checkout_mode' => 'native',
            'external_provider' => 'none',
        ]);

        $owner->update(['team_id' => $team->id]);
        $team->users()->attach($owner->id, ['role' => 'owner']);

        return [$team, $owner];
    }
}

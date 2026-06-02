<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiGeneration;
use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultilingualVideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_queue_multilingual_avatar_videos(): void
    {
        Queue::fake();

        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/admin/ai/multilingual-videos', [
            'team_id' => $team->id,
            'title' => 'Summer Drop',
            'script' => 'Shop our summer collection today.',
            'languages' => ['en', 'es', 'fr'],
        ]);

        $response->assertAccepted()
            ->assertJsonPath('batch.type', 'multilingual_batch')
            ->assertJsonCount(3, 'videos');

        $this->assertDatabaseHas('ai_generations', [
            'team_id' => $team->id,
            'type' => 'multilingual_batch',
        ]);

        $this->assertSame(
            3,
            Video::query()->where('team_id', $team->id)->where('source', 'ai_generated')->count(),
        );

        $this->assertSame(
            4,
            AiGeneration::query()->where('team_id', $team->id)->count(),
        );
    }

    public function test_multilingual_batch_uses_same_preferred_voice_for_all_languages(): void
    {
        Queue::fake();

        [$team, $owner] = $this->createTeamWithOwner();
        Sanctum::actingAs($owner);

        $preferredVoice = 'user-selected-voice-abc123';

        $this->postJson('/api/v1/admin/ai/multilingual-videos', [
            'team_id' => $team->id,
            'title' => 'Summer Drop',
            'script' => 'Shop our summer collection today.',
            'languages' => ['en', 'fr'],
            'voice_id' => $preferredVoice,
        ])->assertAccepted();

        $childGenerations = AiGeneration::query()
            ->where('team_id', $team->id)
            ->where('type', 'avatar_video')
            ->get();

        $this->assertCount(2, $childGenerations);

        foreach ($childGenerations as $generation) {
            $this->assertSame(
                $preferredVoice,
                data_get($generation->input, 'voice_id'),
                "Generation {$generation->id} should use the preferred voice.",
            );
        }
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

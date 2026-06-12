<?php

namespace Tests\Feature\Api\V1;

use App\Models\LiveShow;
use App\Models\LiveShowMessage;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LiveShowLiveOfferTest extends TestCase
{
    use RefreshDatabase;

    public function test_push_live_offer_attaches_product_and_posts_host_message(): void
    {
        [$team, $owner, $liveShow, $product] = $this->createDailyLiveShowFixture();

        Sanctum::actingAs($owner);

        $response = $this->postJson(
            "/api/v1/admin/live-shows/{$liveShow->id}/offers/{$product->id}/push",
            ['host_name' => 'Taylor Host'],
        );

        $response->assertOk()
            ->assertJsonPath('data.offer.id', $product->id)
            ->assertJsonPath('data.offer.appearance', 'in_chat');

        $startsAtMs = (int) $response->json('data.offer.starts_at_ms');
        $this->assertGreaterThan(1_000_000_000_000, $startsAtMs);

        $liveShow->refresh();
        $pivot = $liveShow->featuredProducts()->whereKey($product->id)->first()?->pivot;

        $this->assertNotNull($pivot);
        $this->assertSame($startsAtMs, (int) $pivot->starts_at_ms);
        $this->assertNull($pivot->ends_at_ms);
        $this->assertSame('in_chat', $pivot->appearance);

        $this->assertDatabaseHas('live_show_messages', [
            'live_show_id' => $liveShow->id,
            'sender_type' => 'host',
            'sender_name' => 'Taylor Host',
            'message' => 'Featured: '.$product->title,
        ]);

        $message = LiveShowMessage::query()
            ->where('live_show_id', $liveShow->id)
            ->where('message', 'Featured: '.$product->title)
            ->first();

        $this->assertSame($product->id, data_get($message?->meta, 'offer_product_id'));
    }

    public function test_unpublish_live_offer_sets_end_timestamp(): void
    {
        [$team, $owner, $liveShow, $product] = $this->createDailyLiveShowFixture();

        Sanctum::actingAs($owner);

        $this->postJson(
            "/api/v1/admin/live-shows/{$liveShow->id}/offers/{$product->id}/push",
        )->assertOk();

        $this->postJson(
            "/api/v1/admin/live-shows/{$liveShow->id}/offers/{$product->id}/unpublish",
        )->assertNoContent();

        $liveShow->refresh();
        $pivot = $liveShow->featuredProducts()->whereKey($product->id)->first()?->pivot;

        $this->assertNotNull($pivot);
        $this->assertNotNull($pivot->ends_at_ms);
        $this->assertGreaterThan((int) $pivot->starts_at_ms, (int) $pivot->ends_at_ms);
    }

    public function test_push_live_offer_rejects_non_daily_casts(): void
    {
        [$team, $owner] = $this->createTeamWithOwner();

        $product = Product::query()->create([
            'team_id' => $team->id,
            'title' => 'Upload Product',
            'slug' => 'upload-product',
            'source' => 'native',
            'currency' => 'USD',
            'price' => 42,
        ]);

        $liveShow = LiveShow::query()->create([
            'team_id' => $team->id,
            'title' => 'Upload Show',
            'status' => 'live',
            'starts_at' => now(),
            'settings' => ['source_type' => 'upload'],
        ]);

        Sanctum::actingAs($owner);

        $this->postJson(
            "/api/v1/admin/live-shows/{$liveShow->id}/offers/{$product->id}/push",
        )->assertStatus(422);
    }

    /**
     * @return array{0: Team, 1: User, 2: LiveShow, 3: Product}
     */
    protected function createDailyLiveShowFixture(): array
    {
        [$team, $owner] = $this->createTeamWithOwner();

        $product = Product::query()->create([
            'team_id' => $team->id,
            'title' => 'Live Cap',
            'slug' => 'live-cap',
            'source' => 'native',
            'currency' => 'USD',
            'price' => 520,
        ]);

        $liveShow = LiveShow::query()->create([
            'team_id' => $team->id,
            'title' => 'Daily Live Show',
            'status' => 'live',
            'starts_at' => now(),
            'settings' => [
                'source_type' => 'daily',
                'host_name' => 'Taylor Host',
                'daily' => [
                    'room_name' => 'ls-1-1-daily',
                    'room_url' => 'https://example.daily.co/ls-1-1-daily',
                ],
            ],
        ]);

        return [$team, $owner, $liveShow, $product];
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

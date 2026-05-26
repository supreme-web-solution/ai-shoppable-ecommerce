<?php

namespace Tests\Feature;

use App\Models\Embed;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmbedPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_embed_page_renders_type_specific_attributes(): void
    {
        $owner = User::factory()->create();

        $team = Team::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Embed Team',
            'slug' => 'embed-team',
            'checkout_mode' => 'hybrid',
            'external_provider' => 'none',
        ]);

        $embed = Embed::query()->create([
            'team_id' => $team->id,
            'name' => 'Carousel Demo',
            'type' => 'carousel',
            'slug' => 'carousel-demo',
            'signed_key' => hash('sha256', Str::uuid()->toString()),
            'is_active' => true,
        ]);

        $response = $this->get(route('embed.show', $embed->slug));

        $response->assertOk()
            ->assertSee('data-embed-type="carousel"', false)
            ->assertSee('data-embed-slug="carousel-demo"', false);
    }
}

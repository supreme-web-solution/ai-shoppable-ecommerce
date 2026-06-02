<?php

namespace App\Services\Social;

use App\Models\Embed;
use App\Models\Playlist;
use App\Models\Team;
use App\Models\Video;
use Illuminate\Support\Str;

class SocialShopLinkService
{
    public function shopUrlForEmbed(Embed $embed): string
    {
        return url('/shop/'.$embed->slug);
    }

    public function shopUrlForVideo(Team $team, Video $video): string
    {
        return $this->shopUrlForEmbed($this->ensureShopEmbedForVideo($team, $video));
    }

    public function shopUrlForPlaylist(Team $team, Playlist $playlist): string
    {
        return $this->shopUrlForEmbed($this->ensureShopEmbedForPlaylist($team, $playlist));
    }

    public function ensureShopEmbedForVideo(Team $team, Video $video): Embed
    {
        abort_unless($video->team_id === $team->id, 422, 'Video does not belong to team.');

        $embed = Embed::query()
            ->where('team_id', $team->id)
            ->where('video_id', $video->id)
            ->where('is_active', true)
            ->first();

        if ($embed) {
            return $embed;
        }

        return Embed::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'name' => Str::limit($video->title.' Shop', 255),
            'slug' => $this->uniqueSlug('video-'.$video->id.'-'.Str::slug($video->title ?: 'shop')),
            'type' => 'vertical_feed',
            'signed_key' => hash('sha256', Str::uuid()->toString().Str::random(16)),
            'is_active' => true,
            'settings' => [
                'shop_landing' => true,
            ],
        ]);
    }

    public function ensureShopEmbedForPlaylist(Team $team, Playlist $playlist): Embed
    {
        abort_unless($playlist->team_id === $team->id, 422, 'Playlist does not belong to team.');

        $embed = Embed::query()
            ->where('team_id', $team->id)
            ->where('playlist_id', $playlist->id)
            ->where('is_active', true)
            ->first();

        if ($embed) {
            return $embed;
        }

        return Embed::query()->create([
            'team_id' => $team->id,
            'playlist_id' => $playlist->id,
            'name' => Str::limit($playlist->title.' Shop', 255),
            'slug' => $this->uniqueSlug('playlist-'.$playlist->id.'-'.Str::slug($playlist->slug ?: $playlist->title)),
            'type' => 'vertical_feed',
            'signed_key' => hash('sha256', Str::uuid()->toString().Str::random(16)),
            'is_active' => true,
            'settings' => [
                'shop_landing' => true,
            ],
        ]);
    }

    public function buildCaption(Video $video, string $shopUrl, ?string $custom = null): string
    {
        $base = trim((string) ($custom ?: $video->title));
        $cta = 'Shop now: '.$shopUrl;

        if ($base === '') {
            return $cta;
        }

        if (str_contains($base, $shopUrl)) {
            return $base;
        }

        return $base."\n\n".$cta;
    }

    protected function uniqueSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'shop';
        $candidate = $slug;
        $suffix = 0;

        while (Embed::query()->where('slug', $candidate)->exists()) {
            $suffix++;
            $candidate = $slug.'-'.$suffix;
        }

        return $candidate;
    }
}

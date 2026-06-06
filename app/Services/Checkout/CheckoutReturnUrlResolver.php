<?php

namespace App\Services\Checkout;

use App\Models\Embed;
use App\Models\Order;

class CheckoutReturnUrlResolver
{
    public function resolve(Order $order): ?string
    {
        $explicit = data_get($order->metadata, 'attribution.return_url');

        if (is_string($explicit) && $explicit !== '' && filter_var($explicit, FILTER_VALIDATE_URL)) {
            return $explicit;
        }

        $embedSlug = data_get($order->metadata, 'attribution.embed_slug');
        $videoId = data_get($order->metadata, 'attribution.video_id');
        $videoId = is_numeric($videoId) ? (int) $videoId : null;

        if (! is_string($embedSlug) || $embedSlug === '') {
            $embedSlug = $this->guessEmbedSlug($order, $videoId);
        }

        if (! is_string($embedSlug) || $embedSlug === '') {
            return null;
        }

        $url = url('/embed/'.$embedSlug);

        if ($videoId !== null && $videoId > 0) {
            $url .= '?video='.$videoId;
        }

        return $url;
    }

    protected function guessEmbedSlug(Order $order, ?int $videoId): ?string
    {
        if ($videoId === null || $videoId <= 0) {
            return null;
        }

        $embed = Embed::query()
            ->where('team_id', $order->team_id)
            ->where('is_active', true)
            ->where(function ($query) use ($videoId): void {
                $query->where('video_id', $videoId)
                    ->orWhereHas('playlist.videos', fn ($videoQuery) => $videoQuery->whereKey($videoId));
            })
            ->orderByRaw('CASE WHEN video_id = ? THEN 0 ELSE 1 END', [$videoId])
            ->value('slug');

        return is_string($embed) && $embed !== '' ? $embed : null;
    }
}

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tutorial lesson videos
    |--------------------------------------------------------------------------
    | Set TUTORIAL_VIDEO_* in .env to your own MP4/WebM URLs, or replace
    | the defaults below with hosted walkthrough recordings.
    */
    'lessons' => [
        [
            'id' => 'products',
            'title' => 'Add your products',
            'description' => 'Create or import products with images, prices, and checkout links. Every shoppable moment in your videos starts here.',
            'duration' => '4 min',
            'video_url' => env('TUTORIAL_VIDEO_PRODUCTS'),
            'poster_url' => env('TUTORIAL_POSTER_PRODUCTS'),
            'href' => '/products',
            'cta' => 'Open Products',
        ],
        [
            'id' => 'videos',
            'title' => 'Upload & tag shoppable videos',
            'description' => 'Upload clips or use AI Creator, then add product tags, timed hotspots, coupons, and flash overlays on the edit screen.',
            'duration' => '6 min',
            'video_url' => env('TUTORIAL_VIDEO_VIDEOS'),
            'poster_url' => env('TUTORIAL_POSTER_VIDEOS'),
            'href' => '/content/create',
            'cta' => 'Create video',
        ],
        [
            'id' => 'embed',
            'title' => 'Playlists & embed on your store',
            'description' => 'Group videos into playlists, copy the embed snippet, and paste it on any page that supports custom HTML.',
            'duration' => '5 min',
            'video_url' => env('TUTORIAL_VIDEO_EMBED'),
            'poster_url' => env('TUTORIAL_POSTER_EMBED'),
            'embed_slug' => env('TUTORIAL_EMBED_SLUG'),
            'embed_type' => env('TUTORIAL_EMBED_TYPE', 'vertical_feed'),
            'embed_height' => (int) env('TUTORIAL_EMBED_HEIGHT', 520),
            'href' => '/playlists',
            'cta' => 'Manage playlists',
        ],
        [
            'id' => 'analytics',
            'title' => 'Track views & commerce',
            'description' => 'Publish your embed, then monitor views, engagement, cart adds, and top-performing videos in Analytics.',
            'duration' => '3 min',
            'video_url' => env('TUTORIAL_VIDEO_ANALYTICS'),
            'poster_url' => env('TUTORIAL_POSTER_ANALYTICS'),
            'href' => '/analytics',
            'cta' => 'View analytics',
        ],
        [
            'id' => 'live',
            'title' => 'Live webinars & chat',
            'description' => 'Schedule live shows, collect registrations, and moderate real-time chat with link previews and session bans.',
            'duration' => '5 min',
            'video_url' => env('TUTORIAL_VIDEO_LIVE'),
            'poster_url' => env('TUTORIAL_POSTER_LIVE'),
            'href' => '/live-shows',
            'cta' => 'Live shows',
        ],
    ],

];

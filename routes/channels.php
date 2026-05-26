<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('video.{videoId}', function ($user, int $videoId) {
    return [
        'id' => $user->id,
        'name' => $user->name,
        'video_id' => $videoId,
    ];
});

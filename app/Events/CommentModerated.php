<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommentModerated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $videoId,
        public int $commentId,
        public string $action,
        public ?string $sessionKey = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("video.{$this->videoId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'comment.moderated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'video_id' => $this->videoId,
            'comment_id' => $this->commentId,
            'action' => $this->action,
            'session_key' => $this->sessionKey,
        ];
    }
}

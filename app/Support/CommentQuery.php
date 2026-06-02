<?php

namespace App\Support;

use App\Models\ChatSessionBan;
use App\Models\Comment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class CommentQuery
{
    /**
     * @param  Builder<Comment>|Relation<Comment, Comment, *>  $query
     * @return Builder<Comment>|Relation<Comment, Comment, *>
     */
    public static function applyVisibleForPlayer(
        Builder|Relation $query,
        int $teamId,
        int $videoId,
    ): Builder|Relation {
        $bannedKeys = ChatSessionBan::query()
            ->where('team_id', $teamId)
            ->where('video_id', $videoId)
            ->pluck('session_key')
            ->all();

        return $query
            ->where('team_id', $teamId)
            ->where('video_id', $videoId)
            ->where('is_hidden', false)
            ->when(
                count($bannedKeys) > 0,
                fn (Builder $inner) => $inner->where(function (Builder $group) use ($bannedKeys): void {
                    $group->whereNull('session_key')
                        ->orWhereNotIn('session_key', $bannedKeys);
                }),
            );
    }

    /**
     * @return Builder<Comment>
     */
    public static function visibleForPlayer(int $teamId, int $videoId): Builder
    {
        return self::applyVisibleForPlayer(Comment::query(), $teamId, $videoId);
    }
}

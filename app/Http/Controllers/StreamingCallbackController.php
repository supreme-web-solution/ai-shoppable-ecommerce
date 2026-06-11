<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use App\Services\Integrations\RestreamService;
use Illuminate\Http\Request;

class StreamingCallbackController extends Controller
{
    public function __invoke(Request $request, RestreamService $restream)
    {
        $code = trim((string) $request->query('code', ''));
        $state = trim((string) $request->query('state', ''));
        $scope = trim((string) $request->query('scope', ''));

        if ($code === '' || $state === '') {
            return redirect('/settings/integrations?streaming=cancelled');
        }

        $payload = $restream->consumeAuthorizationState($state);
        if (! is_array($payload)) {
            return redirect('/settings/integrations?streaming=invalid_state');
        }

        $team = Team::query()->find((int) $payload['team_id']);
        $user = User::query()->find((int) $payload['user_id']);

        if (! $team || ! $user) {
            return redirect('/settings/integrations?streaming=invalid_state');
        }

        $canAccessTeam = (int) $team->owner_user_id === (int) $user->id
            || $user->teams()->whereKey($team->id)->exists();

        if (! $canAccessTeam) {
            return redirect('/settings/integrations?streaming=invalid_state');
        }

        try {
            $restream->completeAuthorization($team, $code, $scope);
        } catch (\Throwable) {
            return redirect('/settings/integrations?streaming=failed');
        }

        return redirect('/settings/integrations?streaming=connected');
    }
}

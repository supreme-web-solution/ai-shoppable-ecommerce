<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Integrations\ShopifyTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    public function index()
    {
        $user = request()->user();

        $teams = Team::query()
            ->where('owner_user_id', $user->id)
            ->orWhereHas('users', fn ($query) => $query->whereKey($user->id))
            ->with([
                'owner:id,name,email',
                'users' => fn ($query) => $query->whereKey($user->id)->select('users.id', 'users.name', 'users.email'),
            ])
            ->withCount(['videos', 'products', 'playlists', 'embeds', 'liveShows'])
            ->orderBy('name')
            ->get()
            ->map(function (Team $team) use ($user) {
                $membership = $team->users->first();
                $role = $team->owner_user_id === $user->id
                    ? 'owner'
                    : ($membership?->pivot?->role ?? 'member');

                return [
                    'id' => $team->id,
                    'name' => $team->name,
                    'slug' => $team->slug,
                    'checkout_mode' => $team->checkout_mode,
                    'external_provider' => $team->external_provider,
                    'is_active' => (bool) $team->is_active,
                    'is_current' => (int) $user->team_id === (int) $team->id,
                    'role' => $role,
                    'owner' => $team->owner ? [
                        'id' => $team->owner->id,
                        'name' => $team->owner->name,
                        'email' => $team->owner->email,
                    ] : null,
                    'counts' => [
                        'videos' => (int) $team->videos_count,
                        'products' => (int) $team->products_count,
                        'playlists' => (int) $team->playlists_count,
                        'embeds' => (int) $team->embeds_count,
                        'live_shows' => (int) $team->live_shows_count,
                    ],
                    'created_at' => $team->created_at,
                ];
            })
            ->values();

        return response()->json([
            'data' => $teams,
            'current_team_id' => $user->team_id,
        ]);
    }

    public function activate(Request $request, Team $team)
    {
        $this->authorize('view', $team);

        $request->user()->update(['team_id' => $team->id]);

        return response()->json([
            'team_id' => $team->id,
            'message' => 'Active workspace updated.',
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'checkout_mode' => ['nullable', 'in:native,external,hybrid'],
            'external_provider' => ['nullable', 'in:none,shopify,woocommerce'],
        ]);

        $team = Team::query()->create([
            'owner_user_id' => $request->user()->id,
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? Str::slug($validated['name']).'-'.Str::lower(Str::random(4)),
            'checkout_mode' => $validated['checkout_mode'] ?? 'native',
            'external_provider' => $validated['external_provider'] ?? 'none',
        ]);

        $team->users()->syncWithoutDetaching([
            $request->user()->id => ['role' => 'owner'],
        ]);

        $request->user()->update(['team_id' => $team->id]);

        return response()->json($team, 201);
    }

    public function show(Team $team)
    {
        $this->authorize('view', $team);

        return $team->load(['owner', 'users']);
    }

    public function update(Request $request, Team $team)
    {
        $this->authorize('update', $team);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255'],
            'checkout_mode' => ['sometimes', 'in:native,external,hybrid'],
            'external_provider' => ['sometimes', 'in:none,shopify,woocommerce'],
            'settings' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $team->update($validated);

        if (isset($validated['settings']['integrations']['shopify'])) {
            app(ShopifyTokenService::class)->forget($team->id);
        }

        return $team->fresh();
    }

    public function issueToken(Request $request, Team $team)
    {
        $this->authorize('view', $team);

        abort_unless(
            $request->user()->team_id === $team->id || $request->user()->teams()->whereKey($team->id)->exists(),
            403,
        );

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $abilities = [
            "team:{$team->id}:player",
            "team:{$team->id}:analytics:ingest",
        ];

        $token = $request->user()->createToken(
            $validated['name'] ?? "team-{$team->id}-embed",
            $abilities,
            now()->addDays((int) ($validated['expires_in_days'] ?? 30)),
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'team_id' => $team->id,
            'abilities' => $abilities,
            'expires_at' => now()->addDays((int) ($validated['expires_in_days'] ?? 30))->toIso8601String(),
        ], 201);
    }

    public function destroy(Team $team)
    {
        $this->authorize('delete', $team);

        $team->delete();

        return response()->noContent();
    }
}

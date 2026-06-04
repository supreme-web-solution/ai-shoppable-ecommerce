<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PlatformUserResource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserManagementController extends Controller
{
    use PasswordValidationRules;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $users = User::query()
            ->with('currentTeam:id,name,slug')
            ->withCount(['teams', 'ownedTeams'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($builder) use ($search): void {
                    $builder
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return PlatformUserResource::collection($users)->response();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => $this->passwordRules(),
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'mark_verified' => ['nullable', 'boolean'],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $user = User::query()->create([
                'name' => trim($validated['name']),
                'email' => mb_strtolower(trim($validated['email'])),
                'password' => $validated['password'],
                'email_verified_at' => ($validated['mark_verified'] ?? true) ? now() : null,
            ]);

            $teamId = (int) ($validated['team_id'] ?? 0);

            if ($teamId > 0) {
                $team = Team::query()->findOrFail($teamId);
                $team->users()->syncWithoutDetaching([
                    $user->id => ['role' => 'member'],
                ]);
                $user->forceFill(['team_id' => $team->id])->save();
            } else {
                $team = Team::query()->create([
                    'owner_user_id' => $user->id,
                    'name' => $user->name."'s Store",
                    'slug' => Str::slug($user->name).'-'.Str::lower(Str::random(4)),
                    'checkout_mode' => 'native',
                    'external_provider' => 'none',
                ]);

                $team->users()->attach($user->id, ['role' => 'owner']);
                $user->forceFill(['team_id' => $team->id])->save();
            }

            return $user->refresh();
        });

        $user->load('currentTeam')->loadCount(['teams', 'ownedTeams']);

        return (new PlatformUserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['nullable', 'string', Password::default(), 'confirmed'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'mark_verified' => ['nullable', 'boolean'],
        ]);

        if (isset($validated['name'])) {
            $user->name = trim($validated['name']);
        }

        if (isset($validated['email'])) {
            $user->email = mb_strtolower(trim($validated['email']));
        }

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        if (array_key_exists('mark_verified', $validated)) {
            $user->email_verified_at = $validated['mark_verified'] ? now() : null;
        }

        $user->save();

        if (array_key_exists('team_id', $validated)) {
            $teamId = (int) ($validated['team_id'] ?? 0);

            if ($teamId > 0) {
                $team = Team::query()->findOrFail($teamId);
                $team->users()->syncWithoutDetaching([
                    $user->id => ['role' => 'member'],
                ]);
                $user->forceFill(['team_id' => $team->id])->save();
            }
        }

        $user->load('currentTeam')->loadCount(['teams', 'ownedTeams']);

        return response()->json([
            'data' => (new PlatformUserResource($user))->resolve(),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse|\Symfony\Component\HttpFoundation\Response
    {
        if ($request->user()?->is($user)) {
            return response()->json([
                'message' => 'You cannot delete your own account from this screen.',
            ], 422);
        }

        foreach ($user->ownedTeams()->get() as $team) {
            $hasContent = $team->videos()->exists()
                || $team->products()->exists()
                || $team->playlists()->exists()
                || $team->liveShows()->exists()
                || $team->embeds()->exists();

            if ($hasContent) {
                return response()->json([
                    'message' => "Team \"{$team->name}\" has content. Remove or reassign it before deleting this user.",
                ], 422);
            }
        }

        DB::transaction(function () use ($user): void {
            foreach ($user->ownedTeams()->get() as $team) {
                $team->users()->detach();
                $team->invites()->delete();
                $team->delete();
            }

            $user->teams()->detach();
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->noContent();
    }
}

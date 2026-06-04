<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\User;
use App\Services\Teams\TeamInviteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'invite_token' => ['nullable', 'string', 'max:64'],
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $inviteToken = trim((string) ($input['invite_token'] ?? ''));

            if ($inviteToken !== '') {
                $invite = TeamInvite::query()
                    ->where('token', $inviteToken)
                    ->first();

                if ($invite && $invite->isPending() && Str::lower($invite->email) === Str::lower($user->email)) {
                    app(TeamInviteService::class)->accept($invite, $user);

                    return $user->refresh();
                }
            }

            $team = Team::query()->create([
                'owner_user_id' => $user->id,
                'name' => $input['name']."'s Store",
                'slug' => Str::slug($input['name']).'-'.Str::lower(Str::random(4)),
                'checkout_mode' => 'native',
                'external_provider' => 'none',
            ]);

            $team->users()->attach($user->id, ['role' => 'owner']);
            $user->forceFill(['team_id' => $team->id])->save();

            return $user->refresh();
        });
    }
}

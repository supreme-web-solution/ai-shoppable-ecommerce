<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AuthorizesTeamAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePlaylistRequest extends FormRequest
{
    use AuthorizesTeamAccess;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $team = $this->teamFromInput();

        return $team !== null
            && $this->userCanAccessTeam($team->id)
            && $this->user()?->can('create', \App\Models\Playlist::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'settings' => ['nullable', 'array'],
            'video_ids' => ['nullable', 'array'],
            'video_ids.*' => ['integer', 'exists:videos,id'],
        ];
    }
}

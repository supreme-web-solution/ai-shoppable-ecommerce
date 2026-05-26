<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AuthorizesTeamAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmbedRequest extends FormRequest
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
            && $this->user()?->can('create', \App\Models\Embed::class);
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
            'playlist_id' => ['nullable', 'integer', 'exists:playlists,id'],
            'video_id' => ['nullable', 'integer', 'exists:videos,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:vertical_feed,floating_widget,carousel,product_page'],
            'slug' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'allowed_domains' => ['nullable', 'array'],
            'allowed_domains.*' => ['string', 'max:255'],
            'settings' => ['nullable', 'array'],
        ];
    }
}

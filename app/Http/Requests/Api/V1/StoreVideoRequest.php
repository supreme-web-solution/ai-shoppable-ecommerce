<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AuthorizesTeamAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreVideoRequest extends FormRequest
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
            && $this->user()?->can('create', \App\Models\Video::class);
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
            'description' => ['nullable', 'string'],
            'source' => ['required', 'in:uploaded,ai_generated,live_replay'],
            'status' => ['sometimes', 'in:draft,processing,ready,published,failed'],
            'visibility' => ['sometimes', 'in:public,unlisted,private'],
            'playback_url' => ['nullable', 'url'],
            'thumbnail_url' => ['nullable', 'url'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'published_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
            'metadata.ai_assistant_enabled' => ['nullable', 'boolean'],
            'metadata.knowledge_base_text' => ['nullable', 'string'],
            'metadata.knowledge_sources' => ['nullable', 'array', 'max:3'],
            'metadata.knowledge_sources.*.title' => ['required_with:metadata.knowledge_sources', 'string', 'max:255'],
            'metadata.knowledge_sources.*.content' => ['required_with:metadata.knowledge_sources', 'string'],
            'local_file_path' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

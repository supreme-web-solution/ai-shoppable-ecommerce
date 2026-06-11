<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AuthorizesTeamAccess;
use App\Models\LiveShow;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreLiveShowRequest extends FormRequest
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
            && $this->user()?->can('create', LiveShow::class);
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
            'video_id' => ['nullable', 'integer', 'exists:videos,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:scheduled,live,ended,cancelled'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_premiere' => ['nullable', 'boolean'],
            'settings' => ['nullable', 'array'],
            'settings.host_name' => ['nullable', 'string', 'max:255'],
            'settings.thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'settings.video_url' => ['nullable', 'string', 'max:2048'],
            'settings.source_type' => ['nullable', 'in:ai,upload,url,restream,daily'],
            'settings.registration_title' => ['nullable', 'string', 'max:255'],
            'settings.registration_description' => ['nullable', 'string', 'max:500'],
            'settings.room_title' => ['nullable', 'string', 'max:255'],
            'settings.chat_enabled' => ['nullable', 'boolean'],
            'settings.ai_assistant_enabled' => ['nullable', 'boolean'],
            'settings.knowledge_base_text' => ['nullable', 'string'],
            'settings.knowledge_sources' => ['nullable', 'array', 'max:3'],
            'settings.knowledge_sources.*.title' => ['required', 'string', 'max:255'],
            'settings.knowledge_sources.*.content' => ['required', 'string'],
            'settings.restream' => ['nullable', 'array'],
            'settings.restream.mode' => ['nullable', 'in:go_live,pull_video'],
            'settings.restream.stream_id' => ['nullable', 'string', 'max:100'],
            'settings.restream.stream_key' => ['nullable', 'string', 'max:255'],
            'settings.restream.ingest_url' => ['nullable', 'string', 'max:2048'],
            'settings.restream.player_url' => ['nullable', 'string', 'max:2048'],
            'settings.restream.srt_url' => ['nullable', 'string', 'max:2048'],
            'settings.restream.multistream_targets' => ['nullable', 'array', 'max:20'],
            'settings.restream.multistream_targets.*.name' => ['nullable', 'string', 'max:120'],
            'settings.restream.multistream_targets.*.url' => ['required_with:settings.restream.multistream_targets', 'string', 'max:2048'],
            'settings.restream.multistream_targets.*.profile' => ['nullable', 'string', 'max:40'],
            'settings.restream.multistream_targets.*.video_only' => ['nullable', 'boolean'],
            'featured_product_ids' => ['nullable', 'array'],
            'featured_product_ids.*' => ['integer', 'exists:products,id'],
            'featured_products' => ['nullable', 'array'],
            'featured_products.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'featured_products.*.starts_at_ms' => ['nullable', 'integer', 'min:0'],
            'featured_products.*.ends_at_ms' => ['nullable', 'integer', 'min:0'],
            'featured_products.*.appearance' => ['nullable', 'string', 'in:pin,in_chat,popup'],
            'featured_products.*.cta_url' => ['nullable', 'string', 'max:2048'],
            'featured_products.*.pin_order' => ['nullable', 'integer', 'min:0'],
            'settings.video_duration_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
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
            'video_id' => ['required', 'integer', 'exists:videos,id'],
            'parent_id' => ['nullable', 'integer', 'exists:comments,id'],
            'body' => ['required', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
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
            'cart_id' => ['required', 'integer', 'exists:carts,id'],
            'checkout_mode' => ['required', 'in:native,external,hybrid'],
            'external_provider' => ['nullable', 'in:none,shopify,woocommerce'],
            'video_id' => ['nullable', 'integer', 'exists:videos,id'],
            'embed_slug' => ['nullable', 'string', 'max:255'],
            'return_url' => ['nullable', 'url', 'max:2048'],
            'billing' => ['nullable', 'array'],
            'shipping' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}

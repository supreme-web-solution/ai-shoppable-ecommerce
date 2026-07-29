<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AuthorizesTeamAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
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
            && $this->user()?->can('create', \App\Models\Product::class);
    }

    protected function prepareForValidation(): void
    {
        $productType = $this->input('product_type', 'physical');

        if ($productType === 'physical') {
            $this->merge([
                'product_type' => 'physical',
                'digital_access_type' => null,
                'digital_access_url' => null,
                'digital_file_name' => null,
            ]);
        }
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
            'source' => ['sometimes', 'in:native,shopify,woocommerce'],
            'product_type' => ['sometimes', 'in:physical,digital'],
            'digital_access_type' => [
                'nullable',
                'in:file,link',
                Rule::requiredIf(fn () => $this->input('product_type') === 'digital'),
            ],
            'digital_access_url' => [
                'nullable',
                'url',
                'max:2048',
                Rule::requiredIf(fn () => $this->input('product_type') === 'digital'),
            ],
            'digital_file_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'url'],
            'currency' => ['required', 'string', 'size:3'],
            'price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'sku' => ['nullable', 'string', 'max:255'],
            'inventory' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'variants' => ['nullable', 'array'],
            'variants.*.title' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:255'],
            'variants.*.options' => ['nullable', 'array'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.sale_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.inventory' => ['nullable', 'integer'],
            'variants.*.is_default' => ['nullable', 'boolean'],
        ];
    }
}

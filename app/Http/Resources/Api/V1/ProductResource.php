<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canManage = $request->user()?->can('update', $this->resource) ?? false;

        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'source' => $this->source,
            'product_type' => $this->product_type ?? 'physical',
            // Safe for embed: type/name only — never the download URL until paid.
            'digital_access_type' => $this->digital_access_type,
            'digital_file_name' => $this->digital_file_name,
            'digital_access_url' => $this->when($canManage, $this->digital_access_url),
            'image_url' => $this->image_url,
            'currency' => $this->currency,
            'price' => $this->price,
            'sale_price' => $this->sale_price,
            'sku' => $this->sku,
            'inventory' => $this->inventory,
            'metadata' => $this->metadata,
            'is_active' => $this->is_active,
            'variants' => $this->whenLoaded('variants'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

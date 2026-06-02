<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoProductTagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'video_id' => $this->video_id,
            'product_id' => $this->product_id,
            'starts_at_ms' => $this->starts_at_ms,
            'ends_at_ms' => $this->ends_at_ms,
            'cta_label' => $this->cta_label,
            'position' => $this->position,
            'overlay_kind' => $this->overlay_kind ?? 'product',
            'coupon_code' => $this->coupon_code,
            'discount_percent' => $this->discount_percent,
            'is_pinned' => $this->is_pinned,
            'sort_order' => $this->sort_order,
            'product' => new ProductResource($this->whenLoaded('product')),
        ];
    }
}

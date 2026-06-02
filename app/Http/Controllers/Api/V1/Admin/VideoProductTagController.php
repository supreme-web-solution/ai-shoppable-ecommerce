<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\VideoProductTagResource;
use App\Models\Product;
use App\Models\Video;
use App\Models\VideoProductTag;
use Illuminate\Http\Request;

class VideoProductTagController extends Controller
{
    public function index(Video $video)
    {
        $this->authorize('view', $video);

        return VideoProductTagResource::collection(
            $video->productTags()->with('product.variants')->orderBy('sort_order')->get(),
        );
    }

    public function store(Request $request, Video $video)
    {
        $this->authorize('update', $video);

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'starts_at_ms' => ['nullable', 'integer', 'min:0'],
            'ends_at_ms' => ['nullable', 'integer', 'min:0'],
            'cta_label' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'array'],
            'overlay_kind' => ['nullable', 'string', 'in:product,flash,coupon'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_pinned' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        abort_unless(
            Product::query()->whereKey($validated['product_id'])->where('team_id', $video->team_id)->exists(),
            422,
            'Product must belong to the same team.',
        );

        $tag = $video->productTags()->create([
            ...$validated,
            'sort_order' => $validated['sort_order'] ?? $video->productTags()->count(),
        ]);

        return new VideoProductTagResource($tag->load('product.variants'));
    }

    public function update(Request $request, Video $video, VideoProductTag $productTag)
    {
        $this->authorize('update', $video);
        abort_unless($productTag->video_id === $video->id, 404);

        $validated = $request->validate([
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'starts_at_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'ends_at_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'cta_label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'position' => ['sometimes', 'nullable', 'array'],
            'overlay_kind' => ['sometimes', 'nullable', 'string', 'in:product,flash,coupon'],
            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'discount_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'is_pinned' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (isset($validated['product_id'])) {
            abort_unless(
                Product::query()->whereKey($validated['product_id'])->where('team_id', $video->team_id)->exists(),
                422,
                'Product must belong to the same team.',
            );
        }

        $productTag->update($validated);

        return new VideoProductTagResource($productTag->fresh('product.variants'));
    }

    public function destroy(Video $video, VideoProductTag $productTag)
    {
        $this->authorize('update', $video);
        abort_unless($productTag->video_id === $video->id, 404);

        $productTag->delete();

        return response()->noContent();
    }

    public function sync(Request $request, Video $video)
    {
        $this->authorize('update', $video);

        $validated = $request->validate([
            'tags' => ['required', 'array'],
            'tags.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'tags.*.starts_at_ms' => ['nullable', 'integer', 'min:0'],
            'tags.*.ends_at_ms' => ['nullable', 'integer', 'min:0'],
            'tags.*.cta_label' => ['nullable', 'string', 'max:100'],
            'tags.*.position' => ['nullable', 'array'],
            'tags.*.overlay_kind' => ['nullable', 'string', 'in:product,flash,coupon'],
            'tags.*.coupon_code' => ['nullable', 'string', 'max:64'],
            'tags.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tags.*.is_pinned' => ['nullable', 'boolean'],
            'tags.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $productIds = collect($validated['tags'])->pluck('product_id')->unique()->all();
        $validCount = Product::query()
            ->where('team_id', $video->team_id)
            ->whereIn('id', $productIds)
            ->count();

        abort_unless($validCount === count($productIds), 422, 'All products must belong to the same team.');

        $video->productTags()->delete();

        foreach ($validated['tags'] as $index => $tagData) {
            $video->productTags()->create([
                ...$tagData,
                'sort_order' => $tagData['sort_order'] ?? $index,
            ]);
        }

        return VideoProductTagResource::collection(
            $video->productTags()->with('product.variants')->orderBy('sort_order')->get(),
        );
    }
}

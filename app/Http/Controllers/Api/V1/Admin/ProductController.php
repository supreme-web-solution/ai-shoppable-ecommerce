<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Services\CloudinaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    protected function resolveTeamId(Request $request): int
    {
        $teamId = $request->integer('team_id', $request->user()->team_id);
        abort_unless($request->user()->team_id === $teamId || $request->user()->teams()->whereKey($teamId)->exists(), 403);

        return $teamId;
    }

    public function uploadImage(Request $request, CloudinaryService $cloudinary)
    {
        $teamId = $this->resolveTeamId($request);

        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'file' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:8192'],
        ]);

        abort_unless($teamId === (int) $validated['team_id'], 403);

        $file = $request->file('file');
        $upload = $cloudinary->uploadImage($file->getRealPath(), [
            'public_id' => 'teams/'.$teamId.'/products/'.Str::uuid()->toString(),
        ]);

        return response()->json([
            'image_url' => $upload['secure_url'],
            'public_id' => $upload['public_id'],
        ]);
    }

    public function index(Request $request)
    {
        $teamId = $this->resolveTeamId($request);
        $perPage = min(max((int) $request->input('per_page', 12), 1), 100);

        $products = Product::query()
            ->where('team_id', $teamId)
            ->with('variants')
            ->latest()
            ->paginate($perPage);

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request)
    {
        abort_unless(
            $request->user()->team_id === (int) $request->input('team_id')
                || $request->user()->teams()->whereKey((int) $request->input('team_id'))->exists(),
            403,
        );

        $product = Product::query()->create(collect($request->validated())->except('variants')->all());

        if ($request->filled('variants')) {
            foreach ($request->input('variants', []) as $variantData) {
                $product->variants()->create([
                    'team_id' => $product->team_id,
                    'title' => $variantData['title'] ?? 'Default',
                    'sku' => $variantData['sku'] ?? null,
                    'options' => $variantData['options'] ?? [],
                    'price' => $variantData['price'] ?? $product->price,
                    'sale_price' => $variantData['sale_price'] ?? null,
                    'inventory' => $variantData['inventory'] ?? $product->inventory,
                    'is_default' => (bool) ($variantData['is_default'] ?? false),
                ]);
            }
        }

        return new ProductResource($product->load('variants'));
    }

    public function show(Product $product)
    {
        $this->authorize('view', $product);

        return new ProductResource($product->load('variants'));
    }

    public function update(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255'],
            'source' => ['sometimes', 'in:native,shopify,woocommerce'],
            'description' => ['sometimes', 'nullable', 'string'],
            'image_url' => ['sometimes', 'nullable', 'url'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'sale_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:255'],
            'inventory' => ['sometimes', 'integer'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'variants' => ['sometimes', 'array'],
            'variants.*.title' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:255'],
            'variants.*.options' => ['nullable', 'array'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.sale_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.inventory' => ['nullable', 'integer'],
            'variants.*.is_default' => ['nullable', 'boolean'],
        ]);

        $product->update(collect($validated)->except('variants')->all());

        if (array_key_exists('variants', $validated)) {
            $product->variants()->delete();

            foreach ($validated['variants'] as $variantData) {
                $product->variants()->create([
                    'team_id' => $product->team_id,
                    'title' => $variantData['title'] ?? 'Default',
                    'sku' => $variantData['sku'] ?? null,
                    'options' => $variantData['options'] ?? [],
                    'price' => $variantData['price'] ?? $product->price,
                    'sale_price' => $variantData['sale_price'] ?? null,
                    'inventory' => $variantData['inventory'] ?? $product->inventory,
                    'is_default' => (bool) ($variantData['is_default'] ?? false),
                ]);
            }
        }

        return new ProductResource($product->fresh('variants'));
    }

    public function destroy(Product $product)
    {
        $this->authorize('delete', $product);
        $product->delete();

        return response()->noContent();
    }

    public function duplicate(Product $product)
    {
        $this->authorize('view', $product);
        $this->authorize('create', Product::class);

        $product->load('variants');

        $copy = DB::transaction(function () use ($product): Product {
            $title = Str::limit(trim($product->title).' (copy)', 255, '');
            $slug = $this->uniqueProductSlug($product->team_id, $title);
            $sku = $product->sku ? Str::limit($product->sku.'-copy', 255, '') : null;

            $copy = Product::query()->create([
                'team_id' => $product->team_id,
                'external_id' => null,
                'source' => 'native',
                'title' => $title,
                'slug' => $slug,
                'description' => $product->description,
                'image_url' => $product->image_url,
                'currency' => $product->currency,
                'price' => $product->price,
                'sale_price' => $product->sale_price,
                'sku' => $sku,
                'inventory' => $product->inventory,
                'metadata' => $product->metadata,
                'is_active' => $product->is_active,
            ]);

            foreach ($product->variants as $variant) {
                $copy->variants()->create([
                    'team_id' => $copy->team_id,
                    'title' => $variant->title,
                    'sku' => $variant->sku ? Str::limit($variant->sku.'-copy', 255, '') : null,
                    'options' => $variant->options ?? [],
                    'price' => $variant->price,
                    'sale_price' => $variant->sale_price,
                    'inventory' => $variant->inventory,
                    'is_default' => (bool) $variant->is_default,
                ]);
            }

            if ($copy->variants()->count() === 0) {
                $copy->variants()->create([
                    'team_id' => $copy->team_id,
                    'title' => 'Default',
                    'sku' => $sku,
                    'options' => [],
                    'price' => $copy->price,
                    'sale_price' => $copy->sale_price,
                    'inventory' => $copy->inventory,
                    'is_default' => true,
                ]);
            }

            return $copy;
        });

        return new ProductResource($copy->load('variants'));
    }

    protected function uniqueProductSlug(int $teamId, string $title): string
    {
        $base = Str::slug($title) ?: 'product';
        $candidate = $base;
        $suffix = 2;

        while (Product::query()->where('team_id', $teamId)->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}

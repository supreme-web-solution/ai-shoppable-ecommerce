<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    protected function resolveTeamId(Request $request): int
    {
        $teamId = $request->integer('team_id', $request->user()->team_id);
        abort_unless($request->user()->team_id === $teamId || $request->user()->teams()->whereKey($teamId)->exists(), 403);

        return $teamId;
    }

    public function index(Request $request)
    {
        $teamId = $this->resolveTeamId($request);

        $products = Product::query()
            ->where('team_id', $teamId)
            ->with('variants')
            ->latest()
            ->paginate(15);

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
}

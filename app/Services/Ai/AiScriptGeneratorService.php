<?php

namespace App\Services\Ai;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiScriptGeneratorService
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function generate(array $input): array
    {
        $products = $this->resolveProducts($input);
        $tone = (string) ($input['tone'] ?? 'engaging');
        $language = (string) ($input['language'] ?? 'en');
        $durationSeconds = (int) ($input['duration_seconds'] ?? 45);

        $openAiKey = trim((string) config('services.openai.api_key'));
        if ($openAiKey !== '') {
            $script = $this->generateWithOpenAi($openAiKey, $products, $tone, $language, $durationSeconds, $input);
            if ($script !== null) {
                return $script;
            }
        }

        return $this->generateFromTemplate($products, $tone, $language, $durationSeconds, $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return Collection<int, Product>
     */
    protected function resolveProducts(array $input): Collection
    {
        $teamId = (int) ($input['team_id'] ?? 0);
        $productIds = collect($input['product_ids'] ?? [])->filter()->values();

        if ($productIds->isEmpty()) {
            return collect();
        }

        return Product::query()
            ->where('team_id', $teamId)
            ->whereIn('id', $productIds)
            ->get();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    protected function generateWithOpenAi(
        string $apiKey,
        Collection $products,
        string $tone,
        string $language,
        int $durationSeconds,
        array $input,
    ): ?array {
        $productLines = $products->map(fn (Product $product): string => sprintf(
            '- %s ($%s): %s',
            $product->title,
            $product->sale_price ?? $product->price,
            Str::limit(strip_tags((string) $product->description), 180),
        ))->implode("\n");

        $topic = (string) ($input['topic'] ?? 'product showcase');

        $prompt = trim(<<<PROMPT
Write a {$durationSeconds}-second shoppable social video script in {$language}.
Tone: {$tone}.
Topic: {$topic}.
Products:
{$productLines}

Return JSON with keys: hook, body, cta, full_script, scene_beats (array of {time_ms, line, cta}).
PROMPT);

        try {
            $response = Http::timeout(45)
                ->withToken($apiKey)
                ->post(rtrim((string) config('services.openai.base_url'), '/').'/chat/completions', [
                    'model' => config('services.openai.model', 'gpt-4o-mini'),
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => 'You write concise ecommerce video scripts for vertical social commerce.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('OpenAI script generation failed', [
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 500),
                ]);

                return null;
            }

            $content = (string) data_get($response->json(), 'choices.0.message.content', '');
            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                Log::warning('OpenAI script generation returned invalid JSON', [
                    'content' => Str::limit($content, 500),
                ]);

                return null;
            }

            return [
                'provider' => 'openai',
                'language' => $language,
                'tone' => $tone,
                'duration_seconds' => $durationSeconds,
                'hook' => (string) ($decoded['hook'] ?? ''),
                'body' => (string) ($decoded['body'] ?? ''),
                'cta' => (string) ($decoded['cta'] ?? ''),
                'full_script' => (string) ($decoded['full_script'] ?? ''),
                'scene_beats' => (array) ($decoded['scene_beats'] ?? []),
            ];
        } catch (\Throwable $exception) {
            Log::warning('OpenAI script generation threw an exception', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function generateFromTemplate(
        Collection $products,
        string $tone,
        string $language,
        int $durationSeconds,
        array $input,
    ): array {
        $leadProduct = $products->first();
        $title = $leadProduct?->title ?? 'our featured collection';
        $price = $leadProduct?->sale_price ?? $leadProduct?->price ?? '29.99';
        $topic = (string) ($input['topic'] ?? 'product showcase');

        $hook = match ($tone) {
            'luxury' => "Discover {$title} — crafted for people who notice the details.",
            'urgent' => "Stop scrolling — {$title} is selling fast and today’s offer won’t last.",
            default => "You need to see {$title} in action before you buy anything else.",
        };

        $body = $products->isEmpty()
            ? "In the next {$durationSeconds} seconds, I'll walk you through {$topic} and show exactly why customers are converting from this feed."
            : $products->map(fn (Product $product, int $index): string => sprintf(
                '%d) %s at $%s — %s',
                $index + 1,
                $product->title,
                $product->sale_price ?? $product->price,
                Str::limit(strip_tags((string) $product->description), 90) ?: 'perfect for your next purchase',
            ))->implode(' ');

        $cta = "Tap the product card to add {$title} to cart for just \${$price}.";

        $fullScript = implode("\n\n", array_filter([$hook, $body, $cta]));

        return [
            'provider' => 'template',
            'language' => $language,
            'tone' => $tone,
            'duration_seconds' => $durationSeconds,
            'hook' => $hook,
            'body' => $body,
            'cta' => $cta,
            'full_script' => $fullScript,
            'scene_beats' => [
                ['time_ms' => 0, 'line' => $hook, 'cta' => null],
                ['time_ms' => 12000, 'line' => $body, 'cta' => 'Learn more'],
                ['time_ms' => max(25000, ($durationSeconds - 10) * 1000), 'line' => $cta, 'cta' => 'Shop now'],
            ],
        ];
    }
}

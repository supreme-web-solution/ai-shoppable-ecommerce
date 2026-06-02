<?php

namespace App\Services\Ai;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiScriptGeneratorService
{
    /** Natural presenter pace (~144 wpm). */
    private const WORDS_PER_SECOND = 2.4;

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
        $wordRange = $this->targetWordRange($durationSeconds);

        $prompt = trim(<<<PROMPT
Write a shoppable social video script in {$language} meant to fill exactly {$durationSeconds} seconds when read aloud at a natural presenter pace (~{$this->targetWordCount($durationSeconds)} words).

Tone: {$tone}.
Topic: {$topic}.
Target length: {$wordRange} words in full_script (do not under-write — short scripts are rejected).
Products:
{$productLines}

Structure full_script as multiple short paragraphs (hook, product story, proof/benefits, urgency, CTA).
Spread scene_beats across the full {$durationSeconds}s timeline (first beat near 0ms, last beat near the end).

Return JSON with keys: hook, body, cta, full_script, scene_beats (array of {time_ms, line, cta}).
PROMPT);

        try {
            $response = Http::timeout(45)
                ->withToken($apiKey)
                ->post(rtrim((string) config('services.openai.base_url'), '/').'/chat/completions', [
                    'model' => config('services.openai.model', 'gpt-4o-mini'),
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You write ecommerce video scripts for vertical social commerce. Always match the requested duration via word count (~2.4 words per second). Never return a script that would finish in under 20 seconds unless asked for 15 seconds or less.',
                        ],
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

        $lines = $this->buildTemplateLines($products, $tone, $title, $price, $topic, $durationSeconds);
        $fullScript = $this->padTemplateScriptToDuration($lines, $tone, $title, $topic, $durationSeconds);
        $paragraphs = preg_split("/\n\n+/", trim($fullScript)) ?: [];
        $hook = (string) ($paragraphs[0] ?? '');
        $cta = (string) ($paragraphs[array_key_last($paragraphs)] ?? '');
        $body = count($paragraphs) > 2
            ? implode("\n\n", array_slice($paragraphs, 1, -1))
            : (string) ($paragraphs[1] ?? '');

        $sceneBeats = [];
        $beatLines = array_values(array_filter(preg_split("/\n\n+/", trim($fullScript)) ?: []));
        $lastBeatIndex = max(count($beatLines) - 1, 0);
        foreach ($beatLines as $index => $line) {
            $timeMs = $lastBeatIndex > 0
                ? (int) round(($index / $lastBeatIndex) * max(0, ($durationSeconds - 6) * 1000))
                : 0;
            $sceneBeats[] = [
                'time_ms' => $timeMs,
                'line' => $line,
                'cta' => $index === $lastBeatIndex ? 'Shop now' : ($index === 0 ? null : 'Learn more'),
            ];
        }

        return [
            'provider' => 'template',
            'language' => $language,
            'tone' => $tone,
            'duration_seconds' => $durationSeconds,
            'hook' => $hook,
            'body' => $body,
            'cta' => $cta,
            'full_script' => $fullScript,
            'scene_beats' => $sceneBeats,
        ];
    }

    protected function targetWordCount(int $durationSeconds): int
    {
        return max(30, (int) round($durationSeconds * self::WORDS_PER_SECOND));
    }

    protected function targetWordRange(int $durationSeconds): string
    {
        $target = $this->targetWordCount($durationSeconds);
        $min = (int) round($target * 0.9);
        $max = (int) round($target * 1.15);

        return "{$min}-{$max}";
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<string>
     */
    protected function buildTemplateLines(
        Collection $products,
        string $tone,
        string $title,
        string|float $price,
        string $topic,
        int $durationSeconds,
    ): array {
        $hook = match ($tone) {
            'luxury' => "Discover {$title} — crafted for people who notice the details.",
            'urgent' => "Stop scrolling — {$title} is selling fast and today's offer won't last.",
            'friendly' => "Hey — I found something you'll actually want to use every day: {$title}.",
            default => "You need to see {$title} in action before you buy anything else.",
        };

        $lines = [$hook];

        if ($products->isEmpty()) {
            $lines[] = "Over the next {$durationSeconds} seconds, I'm breaking down {$topic} — what it solves, who it's for, and why shoppers keep coming back.";
            $lines[] = 'Watch how the experience looks in real use, not just polished photos — that is what drives conversions on shoppable video.';
        } else {
            foreach ($products as $index => $product) {
                $detail = Str::limit(strip_tags((string) $product->description), 120)
                    ?: 'built for everyday use and easy to love';
                $lines[] = sprintf(
                    'Highlight %d: %s at $%s — %s.',
                    $index + 1,
                    $product->title,
                    $product->sale_price ?? $product->price,
                    $detail,
                );
            }
        }

        $lines[] = match ($tone) {
            'luxury' => "Every detail on {$title} is intentional — materials, finish, and how it feels the moment you unbox it.",
            'urgent' => 'Inventory on this feed moves quickly — if you wait, you may miss the price you see right now.',
            'friendly' => "I'll keep this simple: here's why {$title} is an easy yes for most shoppers watching.",
            default => "Here's the proof: real customers watch, tap, and buy because the story is clear and the offer is right in the feed.",
        };

        if ($durationSeconds >= 45) {
            $lines[] = match ($tone) {
                'luxury' => 'Compare it to alternatives and you will notice the difference in presentation, packaging, and long-term value.',
                'urgent' => 'Use the next few seconds to decide — the CTA is live on this video and checkout is only a tap away.',
                'friendly' => 'Stick with me — I will walk through the one feature that surprises people once they try it.',
                default => 'I am spacing this walkthrough across the full runtime so you hear the benefits, not just a rushed pitch.',
            };
        }

        if ($durationSeconds >= 60) {
            $lines[] = "By the end of this {$durationSeconds}-second spot, you should know exactly who {$title} is for, what problem it solves, and why now is the right moment to buy.";
        }

        $lines[] = "Tap the product card to add {$title} to cart for just \${$price}.";

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     */
    protected function padTemplateScriptToDuration(
        array $lines,
        string $tone,
        string $title,
        string $topic,
        int $durationSeconds,
    ): string {
        $fillers = [
            'luxury' => [
                "Let me show you {$title} in the context that matters — how it looks, how it feels, and why it belongs in your routine.",
                'This is the part most ads skip: the subtle details that justify the price and keep customers loyal.',
            ],
            'urgent' => [
                "I'm not going to rush the last seconds — you deserve the full picture before you tap buy.",
                'If you are still watching, you are exactly who this offer was made for.',
            ],
            'friendly' => [
                "Think of this as a quick shopping buddy breakdown — no fluff, just what you need to decide.",
                "I'll answer the obvious question: yes, {$title} is worth it for most people watching this feed.",
            ],
            'default' => [
                "I'm using the full {$durationSeconds} seconds so the script matches the video length you selected — hook, proof, and a clear CTA.",
                "Whether you came for {$topic} or for {$title}, stay for the payoff at the end.",
            ],
        ];

        $pool = $fillers[$tone] ?? $fillers['default'];
        $cursor = 0;

        while ($this->wordCount(implode("\n\n", $lines)) < (int) round($this->targetWordCount($durationSeconds) * 0.92)) {
            $lines[] = $pool[$cursor % count($pool)];
            $cursor++;
            if ($cursor > 12) {
                break;
            }
        }

        return implode("\n\n", array_filter($lines));
    }

    protected function wordCount(string $text): int
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if ($text === '') {
            return 0;
        }

        return str_word_count($text);
    }
}

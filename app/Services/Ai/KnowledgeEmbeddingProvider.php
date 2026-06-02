<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

class KnowledgeEmbeddingProvider
{
    /**
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>>
     */
    public function embed(array $inputs): array
    {
        $inputs = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            $inputs,
        ), static fn (string $value): bool => $value !== ''));

        if (empty($inputs)) {
            return [];
        }

        $apiKey = trim((string) config('services.openai.api_key'));
        if ($apiKey !== '') {
            $remote = $this->embedWithOpenAi($apiKey, $inputs);
            if ($remote !== null) {
                return $remote;
            }
        }

        return array_map(fn (string $input): array => $this->embedLocally($input), $inputs);
    }

    /**
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>>|null
     */
    protected function embedWithOpenAi(string $apiKey, array $inputs): ?array
    {
        try {
            $response = Http::timeout(25)
                ->withToken($apiKey)
                ->post(rtrim((string) config('services.openai.base_url'), '/').'/embeddings', [
                    'model' => (string) config('services.openai.embedding_model', 'text-embedding-3-small'),
                    'input' => $inputs,
                    'dimensions' => (int) config('services.openai.embedding_dimensions', 1536),
                ]);

            if (! $response->successful()) {
                return null;
            }

            $rows = (array) data_get($response->json(), 'data', []);
            $vectors = [];

            foreach ($rows as $row) {
                $embedding = (array) data_get($row, 'embedding', []);
                if (empty($embedding)) {
                    return null;
                }

                $vectors[] = array_map('floatval', $embedding);
            }

            return count($vectors) === count($inputs) ? $vectors : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, float>
     */
    protected function embedLocally(string $input): array
    {
        $dimensions = max(8, (int) config('services.openai.embedding_dimensions', 1536));
        $input = mb_strtolower(trim($input));
        $tokens = preg_split('/[^a-z0-9]+/i', $input) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));

        if (empty($tokens)) {
            return array_fill(0, $dimensions, 0.0);
        }

        $vector = array_fill(0, $dimensions, 0.0);
        foreach ($tokens as $token) {
            $slot = abs(crc32($token)) % $dimensions;
            $vector[$slot] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(static fn (float $value): float => $value * $value, $vector)));
        if ($norm <= 0.0) {
            return $vector;
        }

        return array_map(static fn (float $value): float => $value / $norm, $vector);
    }
}

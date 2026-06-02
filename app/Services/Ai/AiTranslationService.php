<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AiTranslationService
{
    /**
     * @return array{language: string, script: string, provider: string}
     */
    public function translateScript(string $script, string $targetLanguageCode): array
    {
        $code = strtolower(trim($targetLanguageCode));
        $openAiKey = trim((string) config('services.openai.api_key'));

        if ($openAiKey !== '' && $code !== '') {
            $translated = $this->translateWithOpenAi($openAiKey, $script, $code);

            if ($translated !== null) {
                return [
                    'language' => $code,
                    'script' => $translated,
                    'provider' => 'openai',
                ];
            }
        }

        return [
            'language' => $code,
            'script' => $this->fallbackTranslate($script, $code),
            'provider' => 'template',
        ];
    }

    public static function languageLabel(string $code): string
    {
        return match (strtolower($code)) {
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'pt' => 'Portuguese',
            'it' => 'Italian',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'ar' => 'Arabic',
            'hi' => 'Hindi',
            'zh' => 'Chinese',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'tr' => 'Turkish',
            'id' => 'Indonesian',
            default => ucfirst($code),
        };
    }

    protected function translateWithOpenAi(string $apiKey, string $script, string $code): ?string
    {
        $label = self::languageLabel($code);

        try {
            $response = Http::timeout(60)
                ->withToken($apiKey)
                ->post(rtrim((string) config('services.openai.base_url'), '/').'/chat/completions', [
                    'model' => config('services.openai.model', 'gpt-4o-mini'),
                    'temperature' => 0.4,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You translate ecommerce live-shopping video scripts. Keep tone persuasive, length similar, and preserve product names/prices. Return only the translated script text.',
                        ],
                        [
                            'role' => 'user',
                            'content' => "Translate this script to {$label} ({$code}):\n\n{$script}",
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                return null;
            }

            $text = trim((string) data_get($response->json(), 'choices.0.message.content', ''));

            return $text !== '' ? $text : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function fallbackTranslate(string $script, string $code): string
    {
        $label = self::languageLabel($code);

        return "[{$label}]\n\n".$script;
    }
}

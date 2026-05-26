<?php

namespace App\Services\Ai;

use App\Models\LiveShow;
use Illuminate\Support\Facades\Http;

class WebinarAssistantService
{
    public function buildReply(LiveShow $liveShow, string $question): string
    {
        $settings = is_array($liveShow->settings) ? $liveShow->settings : [];

        // Combine all knowledge sources (new multi-source format)
        $sources = (array) data_get($settings, 'knowledge_sources', []);
        $knowledge = collect($sources)
            ->filter(fn (mixed $s): bool => is_array($s) && ! empty($s['content']))
            ->map(fn (array $s): string => sprintf("## %s\n%s", $s['title'] ?? 'Source', trim((string) $s['content'])))
            ->implode("\n\n---\n\n");

        // Fallback to legacy single text field
        if ($knowledge === '') {
            $knowledge = trim((string) data_get($settings, 'knowledge_base_text', ''));
        }

        if ($knowledge === '') {
            return 'Thanks for your question. A host will respond shortly.';
        }

        $openAiKey = trim((string) config('services.openai.api_key'));
        if ($openAiKey !== '') {
            $reply = $this->replyWithOpenAi($openAiKey, $question, $knowledge, (string) $liveShow->title);
            if ($reply !== null) {
                return $reply;
            }
        }

        return $this->replyFromKnowledgeBase($question, $knowledge);
    }

    protected function replyWithOpenAi(string $apiKey, string $question, string $knowledge, string $webinarTitle): ?string
    {
        $prompt = trim(<<<PROMPT
You are assisting webinar attendees.
Webinar: {$webinarTitle}

Knowledge base:
{$knowledge}

Attendee question:
{$question}

Reply in plain text with short, helpful guidance. If unknown, say the host will follow up.
PROMPT);

        try {
            $response = Http::timeout(20)
                ->withToken($apiKey)
                ->post(rtrim((string) config('services.openai.base_url'), '/').'/chat/completions', [
                    'model' => config('services.openai.model', 'gpt-4o-mini'),
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a concise webinar assistant.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (! $response->successful()) {
                return null;
            }

            $content = trim((string) data_get($response->json(), 'choices.0.message.content', ''));

            return $content !== '' ? $content : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function replyFromKnowledgeBase(string $question, string $knowledge): string
    {
        $normalizedQuestion = mb_strtolower($question);
        $words = collect(preg_split('/\s+/', $normalizedQuestion) ?: [])
            ->filter(fn (string $w): bool => mb_strlen($w) >= 4)
            ->all();

        if (empty($words)) {
            return 'Thanks for your question. A host will respond shortly.';
        }

        $lines = preg_split('/\R+/', $knowledge) ?: [];
        $bestLine = null;
        $bestScore = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || $trimmed === '---') {
                continue;
            }

            $lineLower = mb_strtolower($trimmed);
            $score = 0;
            foreach ($words as $word) {
                if (str_contains($lineLower, $word)) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestLine = $trimmed;
            }
        }

        if ($bestLine !== null && $bestScore >= 1) {
            return $bestLine;
        }

        return 'Thanks for your question. I could not find that in the knowledge base yet, but the host can answer it live.';
    }
}

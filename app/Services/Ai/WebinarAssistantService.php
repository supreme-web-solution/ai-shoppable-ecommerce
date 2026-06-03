<?php

namespace App\Services\Ai;

use App\Models\Video;
use App\Models\LiveShow;
use Illuminate\Support\Facades\Http;

class WebinarAssistantService
{
    public function __construct(
        protected KnowledgeEmbeddingPipelineService $embeddingPipeline,
        protected KnowledgeSourceService $knowledgeSourceService,
    ) {}

    public function buildReply(LiveShow $liveShow, string $question): string
    {
        $knowledge = $this->embeddingPipeline->contextForLiveShow($liveShow, $question);

        return $this->buildReplyFromKnowledge(
            question: $question,
            knowledge: $knowledge,
            contextTitle: (string) $liveShow->title,
            assistantContext: 'webinar',
        );
    }

    public function buildReplyForVideo(Video $video, string $question): string
    {
        $knowledge = $this->embeddingPipeline->contextForVideo($video, $question);

        return $this->buildReplyFromKnowledge(
            question: $question,
            knowledge: $knowledge,
            contextTitle: (string) $video->title,
            assistantContext: 'live session',
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function extractKnowledgeFromSettings(array $settings): string
    {
        return $this->knowledgeSourceService->toKnowledgeText(
            $this->knowledgeSourceService->normalizeSources($settings),
        );
    }

    public function buildReplyFromKnowledge(
        string $question,
        string $knowledge,
        string $contextTitle,
        string $assistantContext = 'live session',
    ): string
    {
        if ($knowledge === '') {
            return 'Thanks for your question. A host will respond shortly.';
        }

        $openAiKey = trim((string) config('services.openai.api_key'));
        if ($openAiKey !== '') {
            $reply = $this->replyWithOpenAi(
                $openAiKey,
                $question,
                $knowledge,
                $contextTitle,
                $assistantContext,
            );
            if ($reply !== null) {
                return $this->sanitizeAssistantReply($reply, $assistantContext);
            }
        }

        return $this->sanitizeAssistantReply(
            $this->replyFromKnowledgeBase($question, $knowledge),
            $assistantContext,
        );
    }

    protected function replyWithOpenAi(
        string $apiKey,
        string $question,
        string $knowledge,
        string $contextTitle,
        string $assistantContext,
    ): ?string
    {
        $prompt = trim(<<<PROMPT
You are assisting attendees in a {$assistantContext}.
Context title: {$contextTitle}

Knowledge base:
{$knowledge}

Attendee question:
{$question}

Reply in plain text with short, helpful guidance.
Do not call this a webinar unless the context is actually webinar.
If unknown, say the host will follow up.
PROMPT);

        try {
            $response = Http::timeout(20)
                ->withToken($apiKey)
                ->post(rtrim((string) config('services.openai.base_url'), '/').'/chat/completions', [
                    'model' => config('services.openai.model', 'gpt-4o-mini'),
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a concise live chat assistant.'],
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
        $words = $this->questionKeywords($question);

        if (empty($words)) {
            return 'Thanks for your question. A host will respond shortly.';
        }

        $lines = preg_split('/\R+/', $knowledge) ?: [];
        $contentLines = [];
        $bestLine = null;
        $bestScore = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || $trimmed === '---') {
                continue;
            }

            $contentLines[] = $trimmed;
            $lineLower = mb_strtolower($trimmed);
            $score = 0;

            foreach ($words as $word) {
                if ($this->lineContainsKeyword($lineLower, $word)) {
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

        if (count($contentLines) === 1) {
            return $contentLines[0];
        }

        return 'Thanks for your question. I could not find that in the knowledge base yet, but the host can answer it live.';
    }

    /**
     * @return array<int, string>
     */
    protected function questionKeywords(string $question): array
    {
        $normalizedQuestion = mb_strtolower(trim($question));

        return collect(preg_split('/\s+/', $normalizedQuestion) ?: [])
            ->map(static fn (string $word): string => preg_replace('/[^\p{L}\p{N}]+/u', '', $word) ?? '')
            ->filter(static fn (string $word): bool => mb_strlen($word) >= 4)
            ->unique()
            ->values()
            ->all();
    }

    protected function lineContainsKeyword(string $lineLower, string $word): bool
    {
        if ($word === '') {
            return false;
        }

        $quoted = preg_quote($word, '/');
        $pattern = '/\b'.$quoted.'s?\b/u';

        return preg_match($pattern, $lineLower) === 1;
    }

    protected function sanitizeAssistantReply(string $reply, string $assistantContext): string
    {
        if ($assistantContext === 'webinar') {
            return $reply;
        }

        $sanitized = preg_replace('/\bwebinars\b/i', 'live sessions', $reply) ?? $reply;
        $sanitized = preg_replace('/\bwebinar\b/i', 'live session', $sanitized) ?? $sanitized;

        return $sanitized;
    }
}

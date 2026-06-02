<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class TutorialController extends Controller
{
    public function index(): Response
    {
        $lessons = collect(config('tutorial.lessons', []))
            ->map(function (array $lesson): array {
                $videoUrl = $lesson['video_url'] ?? null;
                if (! is_string($videoUrl) || trim($videoUrl) === '') {
                    $lesson['video_url'] = null;
                }

                $posterUrl = $lesson['poster_url'] ?? null;
                if (! is_string($posterUrl) || trim($posterUrl) === '') {
                    $lesson['poster_url'] = null;
                }

                $embedSlug = $lesson['embed_slug'] ?? null;
                if (! is_string($embedSlug) || trim($embedSlug) === '') {
                    $lesson['embed_slug'] = null;
                }

                return $lesson;
            })
            ->values()
            ->all();

        return Inertia::render('tutorial/Index', [
            'lessons' => $lessons,
            'embedScriptUrl' => url('/embed/embed.js'),
        ]);
    }
}
